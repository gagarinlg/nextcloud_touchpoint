#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Touchpoint Contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""
EGroupware → Nextcloud import script
=====================================
Reads a bzip2-compressed EGroupware backup and imports:
  - Contacts    → Nextcloud Cards (CardDAV / oc_cards)
  - Calendar    → Nextcloud Calendar (CalDAV / oc_calendarobjects)
  - Infolog     → Touchpoint (oc_touchpoint_notes + oc_touchpoint_note_contacts)

Before any write, the Nextcloud database is backed up to a timestamped
pg_dump file so the state can always be restored.

Usage:
    python3 import_egroupware.py [--dry-run] [--backup-file PATH] [--nc-user USER]
                                 [--only contacts|calendar|notes]
                                 [--photos-dir PATH]

Defaults:
    --backup-file  /home/gagarin/code/notes/db_backup-202603120649.zip   (or .bz2)
    --nc-user      admin   (Nextcloud user who will own the imported data)
    --nc-db        nextcloud

Photos:
    --photos-dir   Path to a folder containing contact photos.
                   Files must be named by EGroupware contact_id or contact_uid
                   (e.g. "42.jpg", "abc-def-123.png").
                   Supported formats: jpg/jpeg, png, gif, webp.
                   Photos are embedded as base64 PHOTO fields in the vCard.
"""

import argparse
import bz2
import csv
import hashlib
import io
import json
import os
import re
import subprocess
import sys
import uuid
import zipfile
from datetime import datetime, timezone, timedelta
from html import unescape as html_unescape
try:
    import html2text as _html2text
    _H2T = _html2text.HTML2Text()
    _H2T.ignore_links = False
    _H2T.ignore_images = True
    _H2T.body_width = 0   # don't wrap lines
    _H2T.unicode_snob = True
    _HTML2TEXT_AVAILABLE = True
except ImportError:
    _HTML2TEXT_AVAILABLE = False

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

parser = argparse.ArgumentParser(description='Import EGroupware backup into Nextcloud')
parser.add_argument('--dry-run', action='store_true',
                    help='Parse and report without writing to the database')
parser.add_argument('--backup-file', default='/home/gagarin/code/notes/db_backup-202603120649.zip',
                    help='Path to the EGroupware backup (.bz2 or .zip)')
parser.add_argument('--nc-user', default='admin',
                    help='Nextcloud username to own the imported data')
parser.add_argument('--nc-db', default='nextcloud',
                    help='Nextcloud PostgreSQL database name')
parser.add_argument('--nc-db-user', default='www-data',
                    help='PostgreSQL role that owns the Nextcloud tables')
parser.add_argument('--only', choices=['contacts', 'calendar', 'notes'],
                    help='Import only a specific data type')
parser.add_argument('--skip-backup', action='store_true',
                    help='Skip the database backup step (not recommended)')
parser.add_argument('--import-users', action='store_true',
                    help='Create Nextcloud users and groups from EGroupware accounts. '
                         'Skips accounts that already exist in Nextcloud.')
parser.add_argument('--user-password', default=None,
                    help='Default password for newly created Nextcloud users. '
                         'If not set, a random password is generated per user and printed.')
parser.add_argument('--skip-inactive', action='store_true',
                    help='Skip EGroupware user accounts that are disabled or expired '
                         '(account_status != A or account_expires is a past timestamp). '
                         'Without this flag, inactive accounts are created as disabled NC users.')
parser.add_argument('--helper-account', default=None,
                    help='Nextcloud username to assign ownership of entries whose EGroupware '
                         'owner was skipped (inactive). Requires --skip-inactive. '
                         'If not set and --skip-inactive is used, such entries fall back to '
                         '--nc-user.')
parser.add_argument('--photos-dir', default=None,
                    help='Path to folder containing contact photos. Files must be named '
                         'by EGroupware contact_id (e.g. "42.jpg") or contact_uid '
                         '(e.g. "abc-123.jpg"). Supported formats: jpg, jpeg, png, gif, webp.')
parser.add_argument('--calendar-share-group', default=None,
                    help='Nextcloud group name to grant read access to every imported '
                         'personal calendar (e.g. "Alle"). Uses the oc_dav_shares table.')
parser.add_argument('--no-wipe-notes', action='store_true',
                    help='Do NOT delete existing CRM notes before importing. By default the '
                         'importer wipes the notes (scoped to the imported users) for a clean '
                         'reimport; this flag skips that wipe entirely.')
# Database connection / type selection. By default the script auto-detects the
# Nextcloud database from config/config.php; these override the detected values.
parser.add_argument('--db-type', choices=['pgsql', 'mysql', 'sqlite'], default=None,
                    help='Database backend. Default: auto-detect from Nextcloud config.php '
                         '(pgsql / mysql / sqlite3→sqlite).')
parser.add_argument('--db-host', default=None,
                    help='Database host. For pgsql, empty/unset means a local unix socket.')
parser.add_argument('--db-name', default=None,
                    help='Database name (overrides --nc-db and the detected dbname).')
parser.add_argument('--db-user', default=None,
                    help='Database user (overrides --nc-db-user and the detected dbuser).')
parser.add_argument('--db-password', default=None,
                    help='Database password.')
parser.add_argument('--db-port', default=None,
                    help='Database port.')
parser.add_argument('--sqlite-file', default=None,
                    help='Path to the SQLite database file (overrides '
                         '<datadirectory>/<dbname>.db for sqlite installs).')
args = parser.parse_args()

DRY_RUN = args.dry_run
NC_USER = args.nc_user
NC_DB   = args.nc_db
NC_DB_USER = args.nc_db_user
BACKUP_FILE = args.backup_file

# ---------------------------------------------------------------------------
# Database backend resolution (auto-detect from Nextcloud config.php)
# ---------------------------------------------------------------------------

OCC = '/var/www/html/occ'
NC_CONFIG_PHP = '/var/www/html/config/config.php'
NC_DATA_DIR_DEFAULT = '/var/www/html/data'

# Resolved at runtime by resolve_db_config(); DB_TYPE is consulted by the
# cursor wrapper and the portability helpers to branch on the backend.
DB_TYPE = 'pgsql'
DB_CONFIG = {}


def _detect_nc_config():
    """Read Nextcloud's config.php via php and return the $CONFIG dict.

    Returns {} if php or the config file is unavailable."""
    try:
        result = subprocess.run(
            ['php', '-r', f'include {json.dumps(NC_CONFIG_PHP)}; echo json_encode($CONFIG);'],
            capture_output=True, text=True
        )
    except (OSError, FileNotFoundError):
        return {}
    if result.returncode != 0 or not result.stdout.strip():
        return {}
    try:
        cfg = json.loads(result.stdout)
        return cfg if isinstance(cfg, dict) else {}
    except (ValueError, json.JSONDecodeError):
        return {}


def resolve_db_config():
    """Resolve DB_TYPE and DB_CONFIG from (in order) CLI args, config.php, defaults.

    Populates the module-level globals DB_TYPE and DB_CONFIG. CLI args always
    override auto-detected values; if php/config are unavailable we fall back to
    the historic PostgreSQL-over-unix-socket defaults."""
    global DB_TYPE, DB_CONFIG

    cfg = _detect_nc_config()

    detected_type = (cfg.get('dbtype') or '').strip().lower()
    if detected_type == 'sqlite3':
        detected_type = 'sqlite'

    # db-type: CLI > detected > pgsql default
    db_type = args.db_type or detected_type or 'pgsql'

    # Connection values: CLI > detected > legacy defaults.
    host     = args.db_host if args.db_host is not None else cfg.get('dbhost')
    name     = args.db_name or cfg.get('dbname') or NC_DB
    user     = args.db_user or cfg.get('dbuser') or NC_DB_USER
    password = args.db_password if args.db_password is not None else cfg.get('dbpassword')
    port     = args.db_port if args.db_port is not None else cfg.get('dbport')
    datadir  = cfg.get('datadirectory') or NC_DATA_DIR_DEFAULT

    DB_TYPE = db_type
    DB_CONFIG = {
        'host': host or '',
        'name': name,
        'user': user,
        'password': password or '',
        'port': str(port) if port not in (None, '') else '',
        'datadir': datadir,
        'sqlite_file': args.sqlite_file,
    }
    return DB_TYPE, DB_CONFIG

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def log(msg):
    print(msg, flush=True)

def _random_password(length=16):
    import secrets, string
    alphabet = string.ascii_letters + string.digits + '!@#$%^&*'
    return ''.join(secrets.choice(alphabet) for _ in range(length))

def _resolve_nc_user(egw_account_id, egw_to_nc):
    """
    Return the Nextcloud user for an EGroupware account_id.
    Falls back to --helper-account (if set) or NC_USER when unmapped.
    """
    nc_uid = egw_to_nc.get(str(egw_account_id)) if egw_to_nc else None
    if nc_uid:
        return nc_uid
    # Use helper account for entries whose owner was skipped/unmapped
    return args.helper_account or NC_USER

def vcard_escape(s):
    """Escape special characters for vCard values."""
    if not s:
        return ''
    return s.replace('\\', '\\\\').replace('\n', '\\n').replace(',', '\\,').replace(';', '\\;')

PHOTO_MIME = {
    'jpg': 'JPEG', 'jpeg': 'JPEG', 'png': 'PNG',
    'gif': 'GIF', 'webp': 'WEBP',
    # MIME subtype aliases (from fs_mime like "image/jpeg")
    'jpeg': 'JPEG', 'png': 'PNG', 'gif': 'GIF',
}

def build_photo_index(photos_dir):
    """
    Scan photos_dir and return a dict mapping stem → (path, mime_type).
    The stem is the filename without extension (e.g. "42" or "abc-123-def").
    """
    import os
    index = {}
    if not photos_dir or not os.path.isdir(photos_dir):
        if photos_dir:
            log(f'[PHOTOS] Directory not found: {photos_dir}')
        return index
    for fname in os.listdir(photos_dir):
        stem, _, ext = fname.rpartition('.')
        mime = PHOTO_MIME.get(ext.lower())
        if mime and stem:
            index[stem] = (os.path.join(photos_dir, fname), mime)
    log(f'[PHOTOS] Indexed {len(index)} photo(s) in {photos_dir}')
    return index


def vcard_fold(line):
    """Fold long vCard lines at 75 chars."""
    result = []
    while len(line.encode('utf-8')) > 75:
        # Find safe split point
        chunk = line[:75]
        while len(chunk.encode('utf-8')) > 75:
            chunk = chunk[:-1]
        result.append(chunk)
        line = ' ' + line[len(chunk):]
    result.append(line)
    return '\r\n'.join(result)

def ts_to_dt(ts):
    """Unix timestamp → datetime (UTC)."""
    if not ts or ts == 'NULL' or ts == '0':
        return None
    try:
        return datetime.fromtimestamp(int(ts), tz=timezone.utc)
    except (ValueError, OSError):
        return None

def dt_to_ical(dt, date_only=False):
    """datetime → iCalendar date/datetime string."""
    if not dt:
        return ''
    if date_only:
        return dt.strftime('%Y%m%d')
    return dt.strftime('%Y%m%dT%H%M%SZ')

def null(v):
    return v is None or v == 'NULL' or v == ''

def v(d, *keys):
    """Get value from dict, return '' if NULL."""
    for k in keys:
        val = d.get(k)
        if val and val != 'NULL':
            return val
    return ''

# ---------------------------------------------------------------------------
# Step 1: Back up the Nextcloud database
# ---------------------------------------------------------------------------

def backup_database():
    """Back up the Nextcloud database before any write.

    Branches on DB_TYPE (pg_dump / mysqldump / file copy). On any failure this
    logs a WARN and continues for non-pgsql backends rather than hard-failing —
    use --skip-backup to skip entirely."""
    ts = datetime.now().strftime('%Y%m%d%H%M%S')
    db_name = DB_CONFIG.get('name') or NC_DB
    db_user = DB_CONFIG.get('user') or NC_DB_USER
    db_host = DB_CONFIG.get('host') or ''
    db_pass = DB_CONFIG.get('password') or ''
    db_port = DB_CONFIG.get('port') or ''

    if DB_TYPE == 'pgsql':
        backup_path = f'/tmp/nextcloud_backup_before_egw_import_{ts}.sql'
        log(f'[BACKUP] Dumping Nextcloud database to {backup_path} …')
        cmd = ['pg_dump', '-U', db_user, '-d', db_name, '-f', backup_path]
        # Preserve historic behaviour: local peer auth over the unix socket.
        cmd[1:1] = ['-h', db_host] if db_host else ['-h', '/var/run/postgresql']
        env = {**os.environ}
        if db_pass:
            env['PGPASSWORD'] = db_pass
        if db_port:
            cmd += ['-p', str(db_port)]
        result = subprocess.run(cmd, capture_output=True, text=True, env=env)
        if result.returncode != 0:
            log(f'[ERROR] pg_dump failed: {result.stderr}')
            sys.exit(1)
        log(f'[BACKUP] Done ({os.path.getsize(backup_path)//1024} KB). Restore with:')
        log(f'         sudo -u postgres psql -d {db_name} -f {backup_path}')
        return backup_path

    if DB_TYPE == 'mysql':
        backup_path = f'/tmp/nextcloud_backup_before_egw_import_{ts}.sql'
        log(f'[BACKUP] Dumping Nextcloud database to {backup_path} …')
        cmd = ['mysqldump', '-u', db_user, db_name]
        if db_host:
            cmd[1:1] = ['-h', db_host]
        if db_port:
            cmd += ['-P', str(db_port)]
        env = {**os.environ}
        if db_pass:
            # MYSQL_PWD avoids a password-on-command-line warning.
            env['MYSQL_PWD'] = db_pass
        try:
            with open(backup_path, 'w') as out:
                result = subprocess.run(cmd, stdout=out, stderr=subprocess.PIPE, text=True, env=env)
            if result.returncode != 0:
                log(f'[WARN] mysqldump failed (non-fatal, continuing): {result.stderr.strip()[:200]}')
                return None
        except (OSError, FileNotFoundError) as e:
            log(f'[WARN] mysqldump unavailable (non-fatal, continuing): {e}')
            return None
        log(f'[BACKUP] Done ({os.path.getsize(backup_path)//1024} KB). Restore with:')
        log(f'         mysql {db_name} < {backup_path}')
        return backup_path

    if DB_TYPE == 'sqlite':
        import shutil
        src = DB_CONFIG.get('sqlite_file')
        if not src:
            datadir = DB_CONFIG.get('datadir') or NC_DATA_DIR_DEFAULT
            src = os.path.join(datadir, f'{db_name}.db')
        backup_path = f'/tmp/nextcloud_backup_before_egw_import_{ts}.db'
        log(f'[BACKUP] Copying SQLite database {src} → {backup_path} …')
        try:
            shutil.copy2(src, backup_path)
        except OSError as e:
            log(f'[WARN] SQLite backup failed (non-fatal, continuing): {e}')
            return None
        log(f'[BACKUP] Done ({os.path.getsize(backup_path)//1024} KB). Restore by copying it back to {src}')
        return backup_path

    log(f'[WARN] No backup method for DB_TYPE={DB_TYPE!r}; continuing.')
    return None

# ---------------------------------------------------------------------------
# Step 2: Parse the EGroupware backup
# ---------------------------------------------------------------------------

def _decode_dump_blob(value):
    """
    Decode a binary BLOB value as it appears in a SQL dump into raw bytes.

    Different dump dialects serialise binary differently:
      - MySQL hex literal:   0x255044462d312e34...
      - PostgreSQL bytea:    \\x255044462d312e34...
      - base64 (some tools): JVBERi0xLjQ...
    Falls back to a latin-1 byte view of the raw string. Any failure yields None
    so a malformed value can never abort an import.
    """
    if value is None:
        return None
    try:
        if isinstance(value, (bytes, bytearray)):
            return bytes(value)
        s = str(value).strip()
        if s == '':
            return None
        low = s.lower()
        if low.startswith('0x'):
            return bytes.fromhex(s[2:])
        if low.startswith('\\x'):
            return bytes.fromhex(s[2:])
        # base64 heuristic: only the base64 alphabet and a sane length.
        if len(s) % 4 == 0 and re.fullmatch(r'[A-Za-z0-9+/]+={0,2}', s):
            import base64
            return base64.b64decode(s, validate=True)
        return s.encode('latin-1', 'replace')
    except Exception:
        return None


class EgroupwareBackup:
    """
    Parses the EGroupware custom backup format.

    Supports two input formats:
      1. .bz2  — plain bzip2-compressed text backup
      2. .zip  — ZIP archive containing:
           database_backup/<name>  — the text backup (same format as above)
           sqlfs/<dir>/<fs_id>     — binary SQLFS file contents

    Format of the text backup:
      ...PHP schema...

      table: <name>
      col1,col2,...
      row1val1,row1val2,...

      table: <next_name>
      ...
    """

    def __init__(self, path):
        self.path = path
        self.tables = {}    # table_name -> list of dicts
        self._zip = None    # open ZipFile when input is a .zip

    def parse(self):
        log(f'[PARSE] Reading {self.path} …')
        if self.path.endswith('.zip'):
            self._zip = zipfile.ZipFile(self.path, 'r')
            # Find the database backup entry inside the ZIP
            db_entry = next(
                (n for n in self._zip.namelist()
                 if n.startswith('database_backup/')),
                None
            )
            if not db_entry:
                raise ValueError('No database_backup/* entry found in ZIP')
            log(f'[PARSE] ZIP: reading DB backup from {db_entry}')
            raw_bytes = self._zip.read(db_entry)
            text = raw_bytes.decode('utf-8', errors='replace')
            lines_iter = iter(text.splitlines())
        elif self.path.endswith('.bz2'):
            lines_iter = (
                raw.rstrip('\n').rstrip('\r')
                for raw in bz2.open(self.path, 'rt', encoding='utf-8', errors='replace')
            )
        else:
            lines_iter = (
                raw.rstrip('\n').rstrip('\r')
                for raw in open(self.path, 'rt', encoding='utf-8', errors='replace')
            )

        current_table = None
        columns = None
        row_buf = []

        for line in lines_iter:
            if line.startswith('table: '):
                if current_table and columns:
                    self._flush(current_table, columns, row_buf)
                current_table = line[7:].strip()
                columns = None
                row_buf = []
                continue

            if current_table and columns is None:
                columns = next(csv.reader([line]))
                continue

            if current_table and columns is not None:
                row_buf.append(line)

        if current_table and columns:
            self._flush(current_table, columns, row_buf)

        log(f'[PARSE] Loaded {len(self.tables)} tables.')
        for name, rows in self.tables.items():
            log(f'        {name}: {len(rows)} rows')

    def _flush(self, table_name, columns, row_lines):
        rows = []
        reader = csv.reader(row_lines, quotechar='"', skipinitialspace=False)
        for parts in reader:
            if len(parts) != len(columns):
                continue  # skip malformed rows
            row = {}
            for i, col in enumerate(columns):
                val = parts[i]
                row[col] = None if val == 'NULL' else val
            rows.append(row)
        self.tables[table_name] = rows

    def get(self, table, default=None):
        return self.tables.get(table, default if default is not None else [])

    def read_sqlfs_file(self, fs_id):
        """
        Read raw bytes of a SQLFS file by fs_id.

        EGroupware's VFS ('sqlfs') stores file content in one of two ways:
          - filesystem-backed (vfs_storage_mode=fs): content lives in files on disk
            and is captured in the backup ZIP under sqlfs/<bucket>/<fs_id>;
          - DB-backed (vfs_storage_mode=db): content lives inline in the
            egw_sqlfs.fs_content column and therefore in the DB dump itself.
        Try the ZIP first, then fall back to fs_content so DB-backed installs work
        too. Returns bytes, or None if the content is in neither place (e.g. a
        partial backup that did not include the files directory).
        """
        fs_id_str = str(fs_id)

        # 1) filesystem-backed: a blob file in the ZIP under sqlfs/<bucket>/<fs_id>
        #    (bucket = first two digits of fs_id, EGroupware convention).
        if self._zip is not None:
            names = self._zip_sqlfs_names()
            # EGroupware shards sqlfs content files one directory deep, bucketed by
            # fs_id // 100 (e.g. fs_id 1706 -> sqlfs/17/1706, fs_id 47 -> sqlfs/0/47).
            try:
                bucket = str(int(fs_id) // 100)
            except (TypeError, ValueError):
                bucket = fs_id_str[:2]
            entry = f'sqlfs/{bucket}/{fs_id_str}'
            if entry in names:
                return self._zip.read(entry)
            # Fallback: match by fs_id leaf regardless of bucketing/path prefix.
            suffix = f'/{fs_id_str}'
            for name in names:
                if name.endswith(suffix):
                    return self._zip.read(name)

        # 2) DB-backed: content inline in egw_sqlfs.fs_content (present in the dump).
        content = self._sqlfs_content_map().get(fs_id_str)
        if content:
            decoded = _decode_dump_blob(content)
            if decoded:
                return decoded

        return None

    def _zip_sqlfs_names(self):
        """Cached set of sqlfs/ entry names in the ZIP (namelist() is O(n) per call)."""
        if self._zip is None:
            return frozenset()
        cached = getattr(self, '_sqlfs_names_cache', None)
        if cached is None:
            cached = frozenset(
                n for n in self._zip.namelist()
                if n.startswith('sqlfs/') and not n.endswith('/')
            )
            self._sqlfs_names_cache = cached
        return cached

    def _sqlfs_content_map(self):
        """Cached map fs_id (str) → raw fs_content value for DB-backed VFS files."""
        cached = getattr(self, '_sqlfs_content_cache', None)
        if cached is None:
            cached = {}
            for row in self.get('egw_sqlfs'):
                fid = row.get('fs_id')
                val = row.get('fs_content')
                if fid is not None and val not in (None, '', 'NULL'):
                    cached[str(fid)] = val
            self._sqlfs_content_cache = cached
        return cached

    def build_photo_index_from_sqlfs(self):
        """
        Build a dict mapping contact_id (str) → (bytes, mime_type) using the
        egw_sqlfs table and the sqlfs/ files inside the ZIP.
        Returns {} if this is not a ZIP backup.
        """
        if self._zip is None:
            return {}

        # Build the SQLFS directory tree: fs_id → (parent_id, name, mime)
        fs_tree = {}
        for row in self.get('egw_sqlfs'):
            fid  = row.get('fs_id')
            fdir = row.get('fs_dir')
            name = row.get('fs_name') or ''
            mime = row.get('fs_mime') or ''
            if fid:
                fs_tree[fid] = (fdir, name, mime)

        def get_path_parts(fid):
            parts = []
            cur = fid
            for _ in range(12):
                entry = fs_tree.get(cur)
                if not entry:
                    break
                parent, name, _ = entry
                parts.append(name)
                if not parent or parent == cur:
                    break
                cur = parent
            return list(reversed(parts))

        # Find photo entries: name=="photo.jpeg" under /apps/addressbook/<contact_id>/...
        index = {}
        for row in self.get('egw_sqlfs'):
            if (row.get('fs_name') or '').lower() not in ('photo.jpeg', 'photo.jpg', 'photo.png'):
                continue
            fid  = row.get('fs_id')
            mime = row.get('fs_mime') or 'image/jpeg'
            if not fid:
                continue
            parts = get_path_parts(fid)
            # Expect path like ['', 'apps', 'addressbook', '<contact_id>', '.files', 'photo.jpeg']
            try:
                ab_idx = parts.index('addressbook')
                contact_id = parts[ab_idx + 1]
            except (ValueError, IndexError):
                continue
            if not contact_id or not contact_id.isdigit():
                continue
            # Map contact_id → (fs_id, mime)
            if contact_id not in index:
                index[contact_id] = (fid, mime)

        log(f'[PHOTOS] Found {len(index)} contact photo(s) in ZIP SQLFS.')
        return index

    def build_infolog_attachment_index(self):
        """
        Build a dict mapping info_id (str) → list of (fs_id, filename, mime)
        for all file attachments in /apps/infolog/<info_id>/<filename>.
        Returns {} if not a ZIP backup.
        """
        if self._zip is None:
            return {}

        fs_tree = {}
        for row in self.get('egw_sqlfs'):
            fid  = row.get('fs_id')
            fdir = row.get('fs_dir')
            name = row.get('fs_name') or ''
            mime = row.get('fs_mime') or 'application/octet-stream'
            active = row.get('fs_active')
            if fid and active != '0':
                fs_tree[int(fid)] = (int(fdir) if fdir and fdir != 'NULL' else 0, name, mime)

        def get_path_parts(fid):
            parts = []
            cur = int(fid)
            for _ in range(15):
                entry = fs_tree.get(cur)
                if not entry:
                    break
                parent, name, _ = entry
                parts.append(name)
                if not parent or parent == cur:
                    break
                cur = parent
            return list(reversed(parts))

        index = {}  # info_id (str) -> [(fs_id, filename, mime)]
        for row in self.get('egw_sqlfs'):
            mime = row.get('fs_mime') or ''
            if 'directory' in mime:
                continue
            fid  = row.get('fs_id')
            if not fid:
                continue
            parts = get_path_parts(fid)
            # Path like ['', 'apps', 'infolog', '<info_id>', '<filename>']
            try:
                il_idx = parts.index('infolog')
                info_id = parts[il_idx + 1]
                filename = parts[il_idx + 2]
            except (ValueError, IndexError):
                continue
            if not info_id or not info_id.isdigit() or not filename:
                continue
            fname = row.get('fs_name') or filename
            if info_id not in index:
                index[info_id] = []
            index[info_id].append((int(fid), fname, mime))

        total = sum(len(v) for v in index.values())
        log(f'[ATTACHMENTS] Found {total} infolog attachment(s) in ZIP SQLFS across {len(index)} note(s).')
        return index

# ---------------------------------------------------------------------------
# Step 3: Database connection
# ---------------------------------------------------------------------------

class _CursorWrapper:
    """Thin proxy around a DB-API cursor.

    Its only behavioural change is for SQLite: psycopg2 / pymysql /
    mysql.connector all use the `%s` paramstyle, but sqlite3 uses `?`. For
    DB_TYPE=='sqlite' we rewrite `%s`→`?` in the SQL passed to execute /
    executemany. This file's SQL contains no `LIKE '%...%'` patterns or literal
    `%` (verified), so a straight replace is safe; we still restore `%%`→`%`
    afterwards for completeness. All other backends pass through unchanged.
    """

    __slots__ = ('_cur',)

    def __init__(self, cur):
        self._cur = cur

    @staticmethod
    def _rewrite(sql):
        if DB_TYPE == 'sqlite':
            return sql.replace('%s', '?').replace('%%', '%')
        return sql

    @staticmethod
    def _adapt_params(params):
        """SQLite-only: bind datetime values as 'YYYY-MM-DD HH:MM:SS' strings
        (Nextcloud's stored format) instead of relying on Python's implicit
        datetime→TEXT adapter, which is deprecated in 3.12 and slated for
        removal. No-op on other backends."""
        if DB_TYPE != 'sqlite' or params is None:
            return params
        def conv(x):
            return x.strftime('%Y-%m-%d %H:%M:%S') if isinstance(x, datetime) else x
        if isinstance(params, (list, tuple)):
            return type(params)(conv(x) for x in params)
        if isinstance(params, dict):
            return {k: conv(x) for k, x in params.items()}
        return params

    def execute(self, sql, params=None):
        sql = self._rewrite(sql)
        if params is None:
            return self._cur.execute(sql)
        return self._cur.execute(sql, self._adapt_params(params))

    def executemany(self, sql, seq_of_params):
        sql = self._rewrite(sql)
        return self._cur.executemany(sql, (self._adapt_params(p) for p in seq_of_params))

    def fetchone(self):
        return self._cur.fetchone()

    def fetchall(self):
        return self._cur.fetchall()

    @property
    def rowcount(self):
        return self._cur.rowcount

    @property
    def lastrowid(self):
        return self._cur.lastrowid

    def close(self):
        return self._cur.close()

    def __iter__(self):
        return iter(self._cur)


class _ConnWrapper:
    """Wraps a raw DB-API connection so .cursor() returns a _CursorWrapper."""

    __slots__ = ('_conn',)

    def __init__(self, conn):
        self._conn = conn

    def cursor(self):
        return _CursorWrapper(self._conn.cursor())

    def commit(self):
        return self._conn.commit()

    def rollback(self):
        return self._conn.rollback()

    def close(self):
        return self._conn.close()


def get_conn():
    """Connect to the Nextcloud database according to the resolved DB_TYPE.

    Returns a connection object exposing .cursor()/.commit()/.rollback()/.close();
    cursors are paramstyle-normalised via _CursorWrapper."""
    if DB_TYPE == 'pgsql':
        import psycopg2
        host = DB_CONFIG.get('host') or ''
        kwargs = {
            'dbname': DB_CONFIG.get('name') or NC_DB,
            'user':   DB_CONFIG.get('user') or NC_DB_USER,
        }
        if not host:
            # Preserve historic behaviour: local peer auth over the unix socket.
            kwargs['host'] = '/var/run/postgresql'
        else:
            kwargs['host'] = host
            if DB_CONFIG.get('password'):
                kwargs['password'] = DB_CONFIG['password']
            if DB_CONFIG.get('port'):
                kwargs['port'] = DB_CONFIG['port']
        conn = psycopg2.connect(**kwargs)
        conn.autocommit = False
        return _ConnWrapper(conn)

    if DB_TYPE == 'mysql':
        host = DB_CONFIG.get('host') or 'localhost'
        port = int(DB_CONFIG['port']) if DB_CONFIG.get('port') else 3306
        try:
            import pymysql
            conn = pymysql.connect(
                host=host, port=port,
                user=DB_CONFIG.get('user') or NC_DB_USER,
                password=DB_CONFIG.get('password') or '',
                database=DB_CONFIG.get('name') or NC_DB,
                autocommit=False,
            )
        except ImportError:
            import mysql.connector
            # buffered=True so a SELECT's result set is fully read on execute,
            # matching pymysql's default. Without it, doing fetchone() then a
            # second execute() on the same cursor raises "Unread result found".
            conn = mysql.connector.connect(
                host=host, port=port,
                user=DB_CONFIG.get('user') or NC_DB_USER,
                password=DB_CONFIG.get('password') or '',
                database=DB_CONFIG.get('name') or NC_DB,
                autocommit=False,
                buffered=True,
            )
        return _ConnWrapper(conn)

    if DB_TYPE == 'sqlite':
        import sqlite3
        sqlite_file = DB_CONFIG.get('sqlite_file')
        if not sqlite_file:
            datadir = DB_CONFIG.get('datadir') or NC_DATA_DIR_DEFAULT
            dbname  = DB_CONFIG.get('name') or NC_DB
            sqlite_file = os.path.join(datadir, f'{dbname}.db')
        conn = sqlite3.connect(sqlite_file)
        return _ConnWrapper(conn)

    raise ValueError(f'Unsupported DB_TYPE: {DB_TYPE!r}')


# ---------------------------------------------------------------------------
# Portable SQL helpers — branch on DB_TYPE so the same INSERT/IN-list logic
# works on PostgreSQL, MySQL/MariaDB and SQLite.
# ---------------------------------------------------------------------------

def insert_returning_id(cur, sql, params):
    """Execute a plain `INSERT ... VALUES (...)` (no RETURNING) and return the
    new row id portably.

    pgsql: append `RETURNING id` and read it back.
    mysql/sqlite: rely on cursor.lastrowid."""
    if DB_TYPE == 'pgsql':
        cur.execute(sql + ' RETURNING id', params)
        return cur.fetchone()[0]
    cur.execute(sql, params)
    return cur.lastrowid


def insert_ignore(cur, sql, params):
    """Execute an idempotent INSERT that ignores ONLY duplicate-key conflicts.

    pgsql/sqlite: append a target-less `ON CONFLICT DO NOTHING`.
    mysql: append `ON DUPLICATE KEY UPDATE note_id=note_id` — a true no-op that
    catches only duplicate-key violations. (Unlike `INSERT IGNORE`, which also
    silently downgrades truncation / NOT-NULL / type errors to warnings.) Both
    call sites insert a `note_id` column, so the self-assignment is always
    valid."""
    if DB_TYPE == 'mysql':
        cur.execute(sql + ' ON DUPLICATE KEY UPDATE note_id=note_id', params)
    else:
        cur.execute(sql + ' ON CONFLICT DO NOTHING', params)


def in_clause(col, values):
    """Build a portable `col IN (%s, %s, ...)` fragment for a list of values.

    Returns (sql_fragment, params_list). For an empty list returns an
    always-false fragment with no params, so callers never produce `IN ()`."""
    values = list(values)
    if not values:
        return '1=0', []
    placeholders = ', '.join(['%s'] * len(values))
    return f'{col} IN ({placeholders})', values

# ---------------------------------------------------------------------------
# Step 3b: Build user mapping (EGroupware account_id → Nextcloud uid)
# ---------------------------------------------------------------------------

def build_user_map(egw, cur):
    """
    Returns a tuple of three dicts:
      egw_account_email : account_id (str) → email (str)
      egw_account_name  : account_id (str) → display name (str)
      egw_to_nc         : egw account_id (str) → nc_user_id (str)
    """
    # 1. EGroupware: account_id → email and display name from addressbook
    egw_account_email = {}
    egw_account_name  = {}
    for c in egw.get('egw_addressbook'):
        acc_id = c.get('account_id')
        if not acc_id:
            continue
        email = v(c, 'contact_email')
        if email:
            egw_account_email[acc_id] = email
        fn = v(c, 'n_fn') or (
            f'{v(c,"n_given")} {v(c,"n_family")}'.strip()
        ) or v(c, 'org_name') or ''
        if fn:
            egw_account_name[acc_id] = fn

    # 2. Nextcloud: email → uid from oc_accounts
    nc_user_by_email = {}
    cur.execute("SELECT uid, data FROM oc_accounts")
    for row in cur.fetchall():
        uid  = row[0]
        data = row[1]
        if not data:
            continue
        try:
            # Drivers return TEXT/JSON columns differently: psycopg2 may give a
            # parsed dict (jsonb) or str; pymysql/mysql.connector and sqlite3
            # give str or bytes/memoryview. Normalise bytes→str, then json.loads
            # any string; pass an already-parsed dict straight through.
            if isinstance(data, (bytes, bytearray, memoryview)):
                data = bytes(data).decode('utf-8')
            obj = json.loads(data) if isinstance(data, str) else data
            email = (obj.get('email', {}) or {}).get('value', '') or ''
            if email:
                nc_user_by_email[email.lower()] = uid
        except Exception:
            pass

    # 3. Cross-reference EGW accounts → NC users
    egw_to_nc = {}
    for acc_id, email in egw_account_email.items():
        nc_uid = nc_user_by_email.get(email.lower())
        if nc_uid:
            egw_to_nc[acc_id] = nc_uid

    log(f'[USERS] EGW accounts with email: {len(egw_account_email)}, '
        f'NC users found: {len(nc_user_by_email)}, '
        f'matched: {len(egw_to_nc)}')
    for acc_id, nc_uid in egw_to_nc.items():
        log(f'         EGW account_id={acc_id} ({egw_account_email[acc_id]}) → NC user={nc_uid}')

    return egw_account_email, egw_account_name, egw_to_nc


# ---------------------------------------------------------------------------
# Step 4: Import contacts
# ---------------------------------------------------------------------------

def import_users_and_groups(egw, cur, egw_account_email, egw_account_name, args):
    """
    Create Nextcloud users and groups from EGroupware accounts.
    Returns an updated egw_to_nc mapping: {egw_account_id_str: nc_uid}.
    """
    import time as _time

    accounts_list = egw.get('egw_accounts', [])
    now_ts = int(_time.time())

    # Fetch existing NC users
    cur.execute("SELECT uid FROM oc_users")
    existing_nc_uids = {row[0].lower() for row in cur.fetchall()}

    users  = [a for a in accounts_list if a.get('account_type') == 'u']
    groups = [a for a in accounts_list if a.get('account_type') == 'g']

    egw_to_nc = {}   # will be returned
    active_egw_ids = set()   # egw account_ids of active/enabled users
    created_users = []   # (nc_uid, password) — for reporting
    skipped_system = {'admin', 'anonymous', 'guest', 'root'}

    log(f'[USERS] Importing {len(users)} EGW users, {len(groups)} groups …')

    for user in users:
        account_id = user.get('account_id', '')
        login = (user.get('account_lid') or '').strip()
        if not login or login in skipped_system:
            continue

        status   = (user.get('account_status') or '').strip()
        expires  = user.get('account_expires', '-1') or '-1'
        try:
            expires_int = int(expires)
        except ValueError:
            expires_int = -1
        is_active = (status == 'A') and (expires_int == -1 or expires_int > now_ts)

        if args.skip_inactive and not is_active:
            continue

        email = (egw_account_email or {}).get(account_id, '')
        name  = (egw_account_name  or {}).get(account_id, login)

        # Use EGW login as NC uid
        nc_uid = login

        if nc_uid.lower() in existing_nc_uids:
            # User already exists — just record mapping
            egw_to_nc[account_id] = nc_uid
            if is_active:
                active_egw_ids.add(account_id)
            log(f'  [USER] {nc_uid} already exists, skipping creation.')
            continue

        if DRY_RUN:
            egw_to_nc[account_id] = nc_uid
            continue

        # Create user via occ
        password = args.user_password or _random_password()
        env_copy = {**os.environ, 'OC_PASS': password, 'HOME': '/var/www'}
        occ_args = ['php', OCC, 'user:add',
                    f'--display-name={name}',
                    '--password-from-env',
                    '--no-interaction']
        if email:
            occ_args.append(f'--email={email}')
        occ_args.append(nc_uid)

        result = subprocess.run(occ_args, capture_output=True, text=True, env=env_copy)
        out = (result.stdout or '').strip()
        if result.returncode != 0:
            # occ writes messages to stdout, not stderr
            msg = out or (result.stderr or '').strip()
            if 'already exists' in msg.lower():
                # Race or DB inconsistency — just record the mapping
                egw_to_nc[account_id] = nc_uid
                existing_nc_uids.add(nc_uid.lower())
                log(f'  [USER] {nc_uid} already exists (detected via occ), recording mapping.')
            else:
                log(f'  [WARN] Could not create user {nc_uid}: {msg[:200]}')
            continue

        egw_to_nc[account_id] = nc_uid
        existing_nc_uids.add(nc_uid.lower())
        created_users.append((nc_uid, password if not args.user_password else '(shared password)'))
        if is_active:
            active_egw_ids.add(account_id)

        if not is_active:
            subprocess.run(['php', OCC, 'user:disable', '--no-interaction', nc_uid],
                           capture_output=True, text=True,
                           env={**os.environ, 'HOME': '/var/www'})

        log(f'  [USER] Created {nc_uid} (active={is_active})')

    # Create groups and memberships
    # acl_location = -group_id, acl_account = user_id
    group_members = {}   # positive group account_id → [user account_ids]
    for acl in egw.get('egw_acl', []):
        if acl.get('acl_appname') != 'phpgw_group':
            continue
        loc = acl.get('acl_location', '0')
        try:
            grp_id = str(-int(loc))   # negate to get positive group account_id
        except ValueError:
            continue
        user_id = acl.get('acl_account', '')
        if user_id:
            group_members.setdefault(grp_id, []).append(user_id)

    # Also add primary group memberships
    for user in users:
        uid   = user.get('account_id', '')
        pgrp  = user.get('account_primary_group', '')
        if uid and pgrp:
            group_members.setdefault(pgrp, []).append(uid)

    for group in groups:
        grp_id   = group.get('account_id', '')
        grp_name = (group.get('account_lid') or '').strip()
        if not grp_name:
            continue

        if not DRY_RUN:
            result = subprocess.run(
                ['php', OCC, 'group:add', '--no-interaction', grp_name],
                capture_output=True, text=True,
                env={**os.environ, 'HOME': '/var/www'}
            )
            combined = ((result.stdout or '') + (result.stderr or '')).lower()
            if result.returncode != 0 and 'already exists' not in combined:
                log(f'  [WARN] Could not create group {grp_name}: '
                    f'{((result.stdout or result.stderr) or "").strip()[:120]}')

        members = group_members.get(grp_id, [])
        added = 0
        for member_acc_id in members:
            # Only add active/enabled users to groups — disabled users pollute
            # group member lists and break NC's virtual-scroll user picker
            if member_acc_id not in active_egw_ids:
                continue
            nc_uid = egw_to_nc.get(member_acc_id)
            if nc_uid and not DRY_RUN:
                subprocess.run(
                    ['php', OCC, 'group:adduser', '--no-interaction', grp_name, nc_uid],
                    capture_output=True, text=True,
                    env={**os.environ, 'HOME': '/var/www'}
                )
                added += 1

        log(f'  [GROUP] {grp_name}: {added} active member(s) (of {len(members)} total)')

    if created_users:
        log('[USERS] Created users (save these passwords!):')
        for uid, pw in created_users:
            log(f'  {uid:30s}  {pw}')

    # Ensure helper account exists when --skip-inactive is used
    if args.skip_inactive and args.helper_account:
        helper = args.helper_account
        if helper.lower() not in existing_nc_uids and not DRY_RUN:
            password = args.user_password or _random_password()
            env_copy = {**os.environ, 'OC_PASS': password, 'HOME': '/var/www'}
            result = subprocess.run(
                ['php', OCC, 'user:add',
                 f'--display-name=Import Helper',
                 '--password-from-env', '--no-interaction', helper],
                capture_output=True, text=True, env=env_copy
            )
            if result.returncode == 0:
                log(f'  [USER] Created helper account "{helper}" (password: {password})')
            elif 'already exists' not in result.stderr:
                log(f'  [WARN] Could not create helper account "{helper}": '
                    f'{result.stderr.strip()[:200]}')

    log(f'[USERS] Done. egw→NC mappings: {len(egw_to_nc)}')
    return egw_to_nc


def ensure_all_addressbooks(egw, cur, egw_to_nc, group_names):
    """
    Ensure one Nextcloud addressbook per unique contact_owner in egw_addressbook.
    - Positive owner  → personal addressbook of the mapped NC user (or NC_USER fallback)
    - Negative owner  → group addressbook under NC_USER, named after the group
    - Zero            → NC_USER default addressbook

    Returns (owner_to_ab_id, default_ab_id):
      owner_to_ab_id : {contact_owner_str: addressbook_id}
      default_ab_id  : id of NC_USER's default 'contacts' addressbook
    """
    unique_owners = {c.get('contact_owner', '0') or '0'
                     for c in egw.get('egw_addressbook', [])}

    owner_to_ab_id = {}

    def _get_or_create(nc_uid, uri, displayname):
        cur.execute(
            "SELECT id FROM oc_addressbooks WHERE principaluri=%s AND uri=%s",
            (f'principals/users/{nc_uid}', uri)
        )
        row = cur.fetchone()
        if row:
            return row[0]
        return insert_returning_id(
            cur,
            """INSERT INTO oc_addressbooks
               (principaluri, uri, displayname, description, synctoken)
               VALUES (%s, %s, %s, %s, 1)""",
            (f'principals/users/{nc_uid}', uri, displayname, '')
        )

    for owner_str in sorted(unique_owners):
        try:
            owner_int = int(owner_str)
        except ValueError:
            owner_int = 0

        if owner_int > 0:
            # User-owned contact
            nc_uid = _resolve_nc_user(owner_str, egw_to_nc)
            ab_id  = _get_or_create(nc_uid, 'contacts', 'Contacts')
        elif owner_int < 0:
            # Group-owned contact (owner_int = -group_account_id)
            grp_id   = str(-owner_int)
            grp_name = group_names.get(grp_id, f'group-{grp_id}')
            uri      = f'egw-{grp_id}'
            ab_id    = _get_or_create(NC_USER, uri, grp_name)
        else:
            # System / nobody → admin default
            ab_id = _get_or_create(NC_USER, 'contacts', 'Contacts')

        owner_to_ab_id[owner_str] = ab_id

    default_ab_id = _get_or_create(NC_USER, 'contacts', 'Contacts')

    by_ab = {}
    for owner, ab_id in owner_to_ab_id.items():
        by_ab.setdefault(ab_id, []).append(owner)
    for ab_id, owners in sorted(by_ab.items()):
        log(f'[CONTACTS] Addressbook id={ab_id} ← owners {owners}')

    return owner_to_ab_id, default_ab_id


def build_vcard(contact, photo_path=None, photo_mime=None, photo_bytes=None):
    lines = ['BEGIN:VCARD', 'VERSION:3.0']

    uid = v(contact, 'contact_uid') or str(uuid.uuid4())
    lines.append(f'UID:{uid}')

    # Name
    family  = vcard_escape(v(contact, 'n_family'))
    given   = vcard_escape(v(contact, 'n_given'))
    middle  = vcard_escape(v(contact, 'n_middle'))
    prefix  = vcard_escape(v(contact, 'n_prefix'))
    suffix  = vcard_escape(v(contact, 'n_suffix'))
    fn      = vcard_escape(v(contact, 'n_fn') or f'{given} {family}'.strip())
    lines.append(f'N:{family};{given};{middle};{prefix};{suffix}')
    lines.append(f'FN:{fn}')

    # Organisation
    org_name = vcard_escape(v(contact, 'org_name'))
    org_unit = vcard_escape(v(contact, 'org_unit'))
    if org_name:
        lines.append(f'ORG:{org_name};{org_unit}')
    if v(contact, 'contact_title'):
        lines.append(f'TITLE:{vcard_escape(v(contact, "contact_title"))}')
    if v(contact, 'contact_role'):
        lines.append(f'ROLE:{vcard_escape(v(contact, "contact_role"))}')

    # Addresses
    def adr_line(typ, street, street2, city, region, zip_, country):
        if not any([street, street2, city, region, zip_, country]):
            return None
        s  = vcard_escape(street)
        s2 = vcard_escape(street2)
        c  = vcard_escape(city)
        r  = vcard_escape(region)
        z  = vcard_escape(zip_)
        co = vcard_escape(country)
        return f'ADR;TYPE={typ}:;;{s2 or s};{c};{r};{z};{co}'

    adr1 = adr_line('WORK',
        v(contact,'adr_one_street'), v(contact,'adr_one_street2'),
        v(contact,'adr_one_locality'), v(contact,'adr_one_region'),
        v(contact,'adr_one_postalcode'), v(contact,'adr_one_countryname'))
    if adr1:
        lines.append(adr1)
    adr2 = adr_line('HOME',
        v(contact,'adr_two_street'), v(contact,'adr_two_street2'),
        v(contact,'adr_two_locality'), v(contact,'adr_two_region'),
        v(contact,'adr_two_postalcode'), v(contact,'adr_two_countryname'))
    if adr2:
        lines.append(adr2)

    # Phone numbers
    tel_map = [
        ('tel_work',         'WORK,VOICE'),
        ('tel_cell',         'WORK,CELL'),
        ('tel_fax',          'WORK,FAX'),
        ('tel_home',         'HOME,VOICE'),
        ('tel_cell_private', 'HOME,CELL'),
        ('tel_fax_home',     'HOME,FAX'),
        ('tel_assistent',    'WORK,VOICE'),
        ('tel_car',          'CAR,VOICE'),
        ('tel_pager',        'PAGER'),
        ('tel_other',        'VOICE'),
    ]
    prefer = v(contact, 'tel_prefer')
    for field, tel_type in tel_map:
        num = v(contact, field)
        if num:
            pref = ';PREF=1' if field == prefer else ''
            lines.append(f'TEL;TYPE={tel_type}{pref}:{vcard_escape(num)}')

    # Email
    email_work = v(contact, 'contact_email')
    email_home = v(contact, 'contact_email_home')
    if email_work:
        lines.append(f'EMAIL;TYPE=WORK:{vcard_escape(email_work)}')
    if email_home:
        lines.append(f'EMAIL;TYPE=HOME:{vcard_escape(email_home)}')

    # URL
    url = v(contact, 'contact_url')
    if url and not url.startswith('http://192.168') and not url.startswith('https://egroupware'):
        lines.append(f'URL;TYPE=WORK:{vcard_escape(url)}')
    url_home = v(contact, 'contact_url_home')
    if url_home:
        lines.append(f'URL;TYPE=HOME:{vcard_escape(url_home)}')

    # Birthday
    bday = v(contact, 'contact_bday')
    if bday and re.match(r'\d{4}-\d{2}-\d{2}', bday):
        lines.append(f'BDAY:{bday.replace("-","")}')

    # Note
    note = v(contact, 'contact_note')
    if note:
        # Escape newlines properly
        note_esc = note.replace('\\', '\\\\').replace('\n', '\\n').replace('\r', '')
        lines.append(f'NOTE:{note_esc}')

    # Categories
    cat_names = contact.get('_cat_names')  # injected by import_contacts
    if cat_names:
        lines.append('CATEGORIES:' + ','.join(vcard_escape(c) for c in cat_names))

    # Assistant
    assistant = v(contact, 'contact_assistent')
    if assistant:
        lines.append(f'X-ASSISTANT:{vcard_escape(assistant)}')

    # Photo (base64-encoded, vCard 3.0 style)
    import base64 as _base64
    if photo_bytes and photo_mime:
        # Photo supplied as raw bytes (e.g. from ZIP SQLFS)
        vcard_mime = PHOTO_MIME.get(photo_mime.split('/')[-1].lower(), 'JPEG')
        photo_b64 = _base64.b64encode(photo_bytes).decode('ascii')
        lines.append(f'PHOTO;ENCODING=b;TYPE={vcard_mime}:{photo_b64}')
    elif photo_path and photo_mime:
        try:
            with open(photo_path, 'rb') as fh:
                photo_b64 = _base64.b64encode(fh.read()).decode('ascii')
            # vCard 3.0: PHOTO;ENCODING=b;TYPE=JPEG:<base64>
            lines.append(f'PHOTO;ENCODING=b;TYPE={photo_mime}:{photo_b64}')
        except OSError as e:
            log(f'  [WARN] Could not read photo {photo_path}: {e}')

    # Timestamps
    created = ts_to_dt(v(contact, 'contact_created'))
    modified = ts_to_dt(v(contact, 'contact_modified'))
    if created:
        lines.append(f'X-ABCREATED:{dt_to_ical(created)}')
    if modified or created:
        lines.append(f'REV:{dt_to_ical(modified or created)}')

    lines.append('END:VCARD')
    # Join with CRLF and fold long lines
    folded = '\r\n'.join(vcard_fold(l) for l in lines) + '\r\n'
    return folded, uid


def import_contacts(egw, cur, owner_to_ab_id=None, default_ab_id=None, egw_to_nc=None):
    log('[CONTACTS] Importing contacts …')
    contacts = egw.get('egw_addressbook')
    skipped = 0
    imported = 0
    enriched = 0
    touched_ab_ids = set()   # every addressbook id we actually wrote to
    now_ts = int(datetime.now(tz=timezone.utc).timestamp())

    # Build photo index from ZIP SQLFS (preferred) or from --photos-dir
    zip_photo_index = egw.build_photo_index_from_sqlfs()   # contact_id → (fs_id, mime)
    photo_index     = build_photo_index(args.photos_dir)   # stem → (path, mime)

    # Build set of EGW user account_ids so we can detect "account contacts"
    # (contacts that represent EGW user accounts — already present in NC system addressbook)
    egw_user_account_ids = {
        a.get('account_id')
        for a in egw.get('egw_accounts', [])
        if (a.get('account_type') or '').strip() == 'u'
    }

    # Find NC system addressbook ID and its existing card URIs (Database:{uid}.vcf)
    nc_system_ab_id = None
    system_card_by_uid = {}  # nc_uid → card_id
    if not DRY_RUN:
        cur.execute("SELECT id FROM oc_addressbooks WHERE principaluri='principals/system/system' LIMIT 1")
        row = cur.fetchone()
        if row:
            nc_system_ab_id = row[0]
            cur.execute("SELECT id, uri FROM oc_cards WHERE addressbookid=%s", (nc_system_ab_id,))
            for card_id, uri in cur.fetchall():
                # NC system addressbook URIs look like "Database:nlangermann.vcf"
                m = re.match(r'^Database:(.+)\.vcf$', uri, re.IGNORECASE)
                if m:
                    system_card_by_uid[m.group(1).lower()] = card_id

    if egw_to_nc is None:
        egw_to_nc = {}

    # Build category name lookup: cat_id -> name
    cat_by_id = {}
    for cat in egw.get('egw_categories'):
        if cat.get('cat_appname') == 'addressbook':
            cat_by_id[cat.get('cat_id')] = cat.get('cat_name', '')

    # Determine the set of addressbook IDs we're going to write to
    if owner_to_ab_id is None:
        owner_to_ab_id = {}
    if default_ab_id is None:
        default_ab_id = list(owner_to_ab_id.values())[0] if owner_to_ab_id else None

    all_ab_ids = list(set(owner_to_ab_id.values()))

    # Clear previously imported data so the import is idempotent
    if not DRY_RUN and all_ab_ids:
        frag, params = in_clause('addressbookid', all_ab_ids)
        cur.execute(f'DELETE FROM oc_cards_properties WHERE {frag}', params)
        cur.execute(f'DELETE FROM oc_cards WHERE {frag}', params)
        log(f'[CONTACTS] Cleared existing cards for {len(all_ab_ids)} addressbook(s).')

    # Sort so account contacts (account_id set) come first — they are the canonical entry
    # and must be processed before group-addressbook copies with the same UID.
    contacts = sorted(
        contacts,
        key=lambda c: (0 if (c.get('account_id') or '').strip() else 1)
    )

    seen_uids: set = set()

    for c in contacts:
        # Resolve category names and inject into contact dict
        cat_id = v(c, 'cat_id')
        if cat_id:
            # cat_id may be comma-separated list
            names = [cat_by_id[cid.strip()] for cid in cat_id.split(',')
                     if cid.strip() in cat_by_id]
            if names:
                c['_cat_names'] = names
        # Skip entries without any name info
        name = v(c, 'n_fn', 'n_family', 'n_given', 'org_name')
        if not name:
            log(f'  [SKIP] Contact id={c.get("contact_id")} has no name (owner={c.get("contact_owner")}), skipping.')
            skipped += 1
            continue

        # Look up photo: ZIP SQLFS first, then --photos-dir fallback
        photo_path = photo_mime = photo_bytes_val = None
        cid  = c.get('contact_id', '') or ''
        cuid = v(c, 'contact_uid') or ''
        if zip_photo_index and cid in zip_photo_index:
            fs_id, fs_mime = zip_photo_index[cid]
            raw = egw.read_sqlfs_file(fs_id)
            if raw:
                photo_bytes_val = raw
                photo_mime = fs_mime
        if photo_bytes_val is None and photo_index:
            hit = photo_index.get(cid) or photo_index.get(cuid)
            if hit:
                photo_path, photo_mime = hit

        # Check if this contact is an EGW user account contact
        egw_acct_id = (c.get('account_id') or '').strip()
        is_account_contact = egw_acct_id and egw_acct_id in egw_user_account_ids

        try:
            vcard_data, uid = build_vcard(c, photo_path=photo_path, photo_mime=photo_mime,
                                          photo_bytes=photo_bytes_val)
        except Exception as e:
            log(f'  [WARN] Could not build vCard for contact {c.get("contact_id")}: {e}')
            skipped += 1
            continue

        # Deduplicate: skip contacts whose UID was already processed (e.g. group-addressbook copies)
        if uid in seen_uids:
            log(f'  [SKIP-DUP] Contact id={c.get("contact_id")} uid={uid} already imported, skipping duplicate.')
            skipped += 1
            continue

        # For account contacts: enrich the NC system addressbook entry and skip the regular import
        if is_account_contact and nc_system_ab_id and not DRY_RUN:
            nc_uid = (egw_to_nc.get(egw_acct_id) or '').lower()
            sys_card_id = system_card_by_uid.get(nc_uid)
            if sys_card_id:
                etag = hashlib.md5(vcard_data.encode(), usedforsecurity=False).hexdigest()
                size = len(vcard_data.encode('utf-8'))
                cur.execute(
                    'UPDATE oc_cards SET carddata=%s, etag=%s, size=%s, lastmodified=%s WHERE id=%s',
                    (vcard_data.encode('utf-8'), etag, size, now_ts, sys_card_id)
                )
                cur.execute(
                    'DELETE FROM oc_cards_properties WHERE cardid=%s',
                    (sys_card_id,)
                )
                _insert_card_properties(cur, sys_card_id, vcard_data, nc_system_ab_id)
                enriched += 1
                seen_uids.add(uid)
                continue  # skip inserting into regular addressbook

        # Route contact to the correct addressbook based on contact_owner
        contact_owner = c.get('contact_owner', '0') or '0'
        ab_id_for_contact = owner_to_ab_id.get(contact_owner, default_ab_id)

        uri = v(c, 'carddav_name') or f'{uid}.vcf'
        etag = hashlib.md5(vcard_data.encode(), usedforsecurity=False).hexdigest()
        size = len(vcard_data.encode('utf-8'))

        if not DRY_RUN:
            card_id = insert_returning_id(
                cur,
                '''INSERT INTO oc_cards
                   (addressbookid, carddata, uri, lastmodified, etag, size, uid)
                   VALUES (%s, %s, %s, %s, %s, %s, %s)''',
                (ab_id_for_contact, vcard_data.encode('utf-8'), uri,
                 now_ts, etag, size, uid)
            )

            # Populate oc_cards_properties for search (FN, EMAIL, TEL, ORG, NICKNAME)
            _insert_card_properties(cur, card_id, vcard_data, addressbook_id=ab_id_for_contact)
            if ab_id_for_contact is not None:
                touched_ab_ids.add(ab_id_for_contact)

        imported += 1
        seen_uids.add(uid)

    if not DRY_RUN and touched_ab_ids:
        # Bump synctoken once for EVERY addressbook we wrote to (rows may span
        # several). Guarded so an empty/all-skipped run does nothing — avoids
        # the previous UnboundLocalError / last-id-only bug.
        frag, params = in_clause('id', touched_ab_ids)
        cur.execute(f'UPDATE oc_addressbooks SET synctoken = synctoken + 1 WHERE {frag}', params)

    log(f'[CONTACTS] Imported {imported}, enriched system addressbook for {enriched} account contacts, skipped {skipped}.')


# UID must be indexed too: Nextcloud resolves a contact's photo (and other
# per-contact lookups) by searching the UID property, so a card without an indexed
# UID row is unreachable by UID — its embedded photo never renders. Native NC cards
# always carry this row; cards written directly to the DB must add it explicitly.
_PROP_FIELDS = re.compile(
    r'^(FN|EMAIL|TEL|ORG|NICKNAME|N|BDAY|ADR|UID)[^:]*:(.+)$',
    re.MULTILINE
)

def _insert_card_properties(cur, card_id, vcard_data, addressbook_id=None):
    """Extract key vCard properties and store in oc_cards_properties for search."""
    for m in _PROP_FIELDS.finditer(vcard_data):
        name = m.group(1)
        value = m.group(2).replace('\\n', ' ').replace('\\,', ',').replace('\\;', ';').strip()
        if not value:
            continue
        # Truncate to column limit
        value = value[:255]
        cur.execute(
            '''INSERT INTO oc_cards_properties (addressbookid, cardid, name, value, preferred)
               VALUES (%s, %s, %s, %s, 1)''',
            (addressbook_id, card_id, name, value)
        )


# ---------------------------------------------------------------------------
# Step 5: Import calendar
# ---------------------------------------------------------------------------

def ensure_all_calendars(egw, cur, egw_to_nc, share_group=None):
    """
    Ensure one Nextcloud calendar per unique cal_owner in egw_cal.
    If share_group is set, grants read access to that group for every calendar.
    Returns (owner_to_cal_id, default_cal_id).
    """
    unique_owners = {e.get('cal_owner', '0') or '0'
                     for e in egw.get('egw_cal', [])}

    owner_to_cal_id = {}

    def _get_or_create(nc_uid):
        cur.execute(
            "SELECT id FROM oc_calendars WHERE principaluri=%s AND uri=%s",
            (f'principals/users/{nc_uid}', 'personal')
        )
        row = cur.fetchone()
        if row:
            return row[0]
        return insert_returning_id(
            cur,
            """INSERT INTO oc_calendars
               (principaluri, uri, displayname, timezone, components, synctoken)
               VALUES (%s, %s, %s, NULL, 'VEVENT,VTODO', 1)""",
            (f'principals/users/{nc_uid}', 'personal', 'Personal')
        )

    def _share_with_group(cal_id, nc_uid, group_name):
        """Grant group read access to a calendar via oc_dav_shares."""
        principaluri = f'principals/groups/{group_name}'
        cur.execute(
            "SELECT id FROM oc_dav_shares WHERE resourceid=%s AND principaluri=%s AND type='calendar'",
            (cal_id, principaluri)
        )
        if cur.fetchone():
            return  # already shared
        cur.execute(
            """INSERT INTO oc_dav_shares (principaluri, type, access, resourceid)
               VALUES (%s, 'calendar', 3, %s)""",
            (principaluri, cal_id)
        )

    # Build set of disabled NC users so we can skip/clean up sharing for them
    disabled_nc_uids: set = set()
    if share_group:
        cur.execute(
            "SELECT userid FROM oc_preferences WHERE appid='core' AND configkey='enabled' AND configvalue='false'"
        )
        disabled_nc_uids = {row[0].lower() for row in cur.fetchall()}
        if disabled_nc_uids:
            log(f'[CALENDAR] Skipping group share for {len(disabled_nc_uids)} disabled account(s)')
        # Also remove any stale shares that may exist from a previous import run
        # for calendars owned by now-disabled users. Done portably: SELECT the
        # candidate share rows, derive the owning userid from principaluri in
        # Python (no SPLIT_PART), then DELETE the matching share ids by primary
        # key (no multi-table DELETE/USING).
        principaluri = f'principals/groups/{share_group}'
        cur.execute(
            """SELECT s.id, c.principaluri
                 FROM oc_dav_shares s
                 JOIN oc_calendars c ON s.resourceid = c.id
                WHERE s.type='calendar' AND s.principaluri=%s""",
            (principaluri,)
        )
        stale_share_ids = []
        for share_id, cal_principal in cur.fetchall():
            # principaluri looks like 'principals/users/<userid>' — segment 3.
            segs = (cal_principal or '').split('/')
            owner_uid = segs[2] if len(segs) > 2 else ''
            if owner_uid and owner_uid.lower() in disabled_nc_uids:
                stale_share_ids.append(share_id)
        if stale_share_ids:
            frag, params = in_clause('id', stale_share_ids)
            cur.execute(f'DELETE FROM oc_dav_shares WHERE {frag}', params)

    for owner_str in sorted(unique_owners):
        try:
            owner_int = int(owner_str)
        except ValueError:
            owner_int = 0
        nc_uid = _resolve_nc_user(owner_str, egw_to_nc) if owner_int > 0 else NC_USER
        cal_id = _get_or_create(nc_uid)
        owner_to_cal_id[owner_str] = cal_id
        if share_group and nc_uid.lower() not in disabled_nc_uids:
            _share_with_group(cal_id, nc_uid, share_group)

    default_cal_id = _get_or_create(NC_USER)
    if share_group and NC_USER.lower() not in disabled_nc_uids:
        _share_with_group(default_cal_id, NC_USER, share_group)

    unique_ids = set(owner_to_cal_id.values())
    log(f'[CALENDAR] Using {len(unique_ids)} calendar(s) for {len(unique_owners)} owner(s)')
    if share_group:
        log(f'[CALENDAR] Calendars shared with group "{share_group}"')

    return owner_to_cal_id, default_cal_id


# EGroupware recur_type constants
RECUR_DAILY   = 1
RECUR_WEEKLY  = 2
RECUR_MONTHLY_MDAY = 3
RECUR_MONTHLY_WDAY = 4
RECUR_YEARLY  = 5

RECUR_WEEKDAYS = {1:'MO', 2:'TU', 4:'WE', 8:'TH', 16:'FR', 32:'SA', 64:'SU'}


def build_rrule(repeat):
    rtype = int(v(repeat, 'recur_type') or 0)
    interval = int(v(repeat, 'recur_interval') or 1)
    data = int(v(repeat, 'recur_data') or 0)

    if rtype == RECUR_DAILY:
        return f'RRULE:FREQ=DAILY;INTERVAL={interval}'
    elif rtype == RECUR_WEEKLY:
        days = ','.join(d for bit, d in RECUR_WEEKDAYS.items() if data & bit)
        if days:
            return f'RRULE:FREQ=WEEKLY;INTERVAL={interval};BYDAY={days}'
        return f'RRULE:FREQ=WEEKLY;INTERVAL={interval}'
    elif rtype == RECUR_MONTHLY_MDAY:
        return f'RRULE:FREQ=MONTHLY;INTERVAL={interval}'
    elif rtype == RECUR_MONTHLY_WDAY:
        return f'RRULE:FREQ=MONTHLY;INTERVAL={interval}'
    elif rtype == RECUR_YEARLY:
        return f'RRULE:FREQ=YEARLY;INTERVAL={interval}'
    return None


def ical_escape(s):
    if not s:
        return ''
    return s.replace('\\', '\\\\').replace(';', '\\;').replace(',', '\\,').replace('\n', '\\n').replace('\r', '')


def ical_fold(line):
    """Fold iCal lines at 75 octets."""
    result = []
    encoded = line.encode('utf-8')
    while len(encoded) > 75:
        chunk = line[:75]
        while len(chunk.encode('utf-8')) > 75:
            chunk = chunk[:-1]
        result.append(chunk)
        line = ' ' + line[len(chunk):]
        encoded = line.encode('utf-8')
    result.append(line)
    return '\r\n'.join(result)


def _attendee_email(acc_id, acct, egw_account_email):
    """Return best available email for an EGroupware user account."""
    return (egw_account_email or {}).get(acc_id) or f'{acct.get("account_lid", acc_id)}@import'


def _attendee_cn(acc_id, acct, egw_account_name):
    """Return display name for an EGroupware user account."""
    return (egw_account_name or {}).get(acc_id) or acct.get('account_lid', acc_id)


def build_vevent(event, dates_by_id, repeats_by_id, users_by_id, accounts,
                 egw_account_email=None, egw_account_name=None,
                 extra_exdates=None, is_cancelled=False):
    """Build a VCALENDAR string for a single event."""
    cal_id = event.get('cal_id')
    uid = v(event, 'cal_uid') or f'egw-cal-{cal_id}@import'

    date_rows = dates_by_id.get(cal_id, [])
    if not date_rows:
        return None, None

    # Use the first occurrence for the main event
    main_date = date_rows[0]
    dtstart = ts_to_dt(v(main_date, 'cal_start'))
    dtend   = ts_to_dt(v(main_date, 'cal_end'))
    if not dtstart:
        return None, None

    modified = ts_to_dt(v(event, 'cal_modified'))
    created  = ts_to_dt(v(event, 'cal_created')) or dtstart

    lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//EGroupware Import//EN',
        'BEGIN:VEVENT',
        f'UID:{uid}',
        f'DTSTAMP:{dt_to_ical(datetime.now(tz=timezone.utc))}',
        f'DTSTART:{dt_to_ical(dtstart)}',
        f'DTEND:{dt_to_ical(dtend)}',
        f'CREATED:{dt_to_ical(created)}',
        f'LAST-MODIFIED:{dt_to_ical(modified or created)}',
        f'SEQUENCE:{v(event, "cal_etag") or "0"}',
    ]

    summary = v(event, 'cal_title')
    if summary:
        lines.append(f'SUMMARY:{ical_escape(summary)}')
    description = v(event, 'cal_description')
    if description:
        lines.append(f'DESCRIPTION:{ical_escape(description)}')
    location = v(event, 'cal_location')
    if location:
        lines.append(f'LOCATION:{ical_escape(location)}')

    # Priority
    priority = v(event, 'cal_priority')
    if priority and priority != '2':  # 2=normal
        prio_map = {'1': '9', '2': '5', '3': '1'}
        lines.append(f'PRIORITY:{prio_map.get(priority, "5")}')

    # Class (privacy)
    if v(event, 'cal_public') == '0':
        lines.append('CLASS:PRIVATE')

    # Cancelled status
    if is_cancelled:
        lines.append('STATUS:CANCELLED')

    # Recurrence rule
    repeat = repeats_by_id.get(cal_id)
    if repeat:
        rrule = build_rrule(repeat)
        if rrule:
            lines.append(rrule)
        # Exception dates from egw_cal_dates
        exceptions = [d for d in date_rows if v(d, 'recur_exception') == '1']
        for exc in exceptions:
            exc_dt = ts_to_dt(v(exc, 'cal_start'))
            if exc_dt:
                lines.append(f'EXDATE:{dt_to_ical(exc_dt)}')
        # Extra exception dates from deleted recurring exception events
        for exc_dt in (extra_exdates or []):
            lines.append(f'EXDATE:{dt_to_ical(exc_dt)}')

    # Organizer + Attendees
    event_users = users_by_id.get(cal_id, [])

    # Count distinct human attendees to decide whether to include scheduling info
    human_users = [eu for eu in event_users if eu.get('cal_user_type') == 'u']
    external_attendees = [eu for eu in event_users
                          if eu.get('cal_user_type') == 'e'
                          and '@' in (v(eu, 'cal_user_attendee') or '')]
    is_multi_person = len(human_users) > 1 or external_attendees

    # Determine organizer: prefer CHAIR role, fall back to cal_owner
    organizer_acc_id = None
    for eu in human_users:
        if (eu.get('cal_role') or '').upper() == 'CHAIR':
            organizer_acc_id = eu.get('cal_user_id')
            break
    if not organizer_acc_id:
        organizer_acc_id = event.get('cal_owner')

    # Only emit ORGANIZER/ATTENDEE for events with more than one person
    if is_multi_person and organizer_acc_id:
        org_acct = accounts.get(organizer_acc_id)
        if org_acct:
            org_email = _attendee_email(organizer_acc_id, org_acct, egw_account_email)
            org_cn    = _attendee_cn(organizer_acc_id, org_acct, egw_account_name)
            lines.append(f'ORGANIZER;CN={ical_escape(org_cn)}:mailto:{org_email}')

    if is_multi_person:
        for eu in event_users:
            if eu.get('cal_user_type') == 'u':
                acc_id = eu.get('cal_user_id')
                acct = accounts.get(acc_id)
                if not acct:
                    continue
                status = eu.get('cal_status', 'A') or 'A'
                partstat = {'A': 'ACCEPTED', 'D': 'DECLINED', 'U': 'TENTATIVE',
                            'T': 'TENTATIVE', 'R': 'DECLINED'}.get(status.upper(), 'NEEDS-ACTION')
                role = (eu.get('cal_role') or 'REQ-PARTICIPANT').upper()
                if role not in ('CHAIR', 'REQ-PARTICIPANT', 'OPT-PARTICIPANT', 'NON-PARTICIPANT'):
                    role = 'REQ-PARTICIPANT'
                email = _attendee_email(acc_id, acct, egw_account_email)
                cn    = _attendee_cn(acc_id, acct, egw_account_name)
                lines.append(
                    f'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={role};PARTSTAT={partstat}'
                    f';CN={ical_escape(cn)}:mailto:{email}'
                )
            elif eu.get('cal_user_type') == 'e':
                attendee = v(eu, 'cal_user_attendee')
                if attendee and '@' in attendee:
                    # Extract email and CN from "Name <email>" format
                    m_email = re.search(r'<([^>]+)>', attendee)
                    email = m_email.group(1) if m_email else attendee
                    m_cn = re.match(r'^([^<]+)<', attendee)
                    cn = m_cn.group(1).strip() if m_cn else ''
                    cn_part = f';CN={ical_escape(cn)}' if cn else ''
                    lines.append(
                        f'ATTENDEE;CUTYPE=INDIVIDUAL;ROLE=REQ-PARTICIPANT'
                        f';PARTSTAT=NEEDS-ACTION{cn_part}:mailto:{email}'
                    )

    lines.append('END:VEVENT')
    lines.append('END:VCALENDAR')

    ical = '\r\n'.join(ical_fold(l) for l in lines) + '\r\n'
    return ical, uid


def import_calendar(egw, cur, owner_to_cal_id=None, default_cal_id=None,
                    egw_account_email=None, egw_account_name=None):
    log('[CALENDAR] Importing calendar events …')

    events   = egw.get('egw_cal')
    dates    = egw.get('egw_cal_dates')
    repeats  = egw.get('egw_cal_repeats')
    users    = egw.get('egw_cal_user')
    accounts_list = egw.get('egw_accounts')

    # Index by cal_id
    dates_by_id   = {}
    for d in dates:
        cid = d.get('cal_id')
        if cid not in dates_by_id:
            dates_by_id[cid] = []
        dates_by_id[cid].append(d)

    repeats_by_id = {r.get('cal_id'): r for r in repeats}
    users_by_id   = {}
    for u in users:
        cid = u.get('cal_id')
        if cid not in users_by_id:
            users_by_id[cid] = []
        users_by_id[cid].append(u)
    accounts = {a.get('account_id'): a for a in accounts_list}

    imported = 0
    skipped  = 0
    touched_cal_ids = set()   # every calendar id we actually wrote to
    now_ts = int(datetime.now(tz=timezone.utc).timestamp())

    if owner_to_cal_id is None:
        owner_to_cal_id = {}
    if default_cal_id is None:
        default_cal_id = list(owner_to_cal_id.values())[0] if owner_to_cal_id else None

    all_cal_ids = list(set(owner_to_cal_id.values()))

    # Clear previously imported events so the import is idempotent
    if not DRY_RUN and all_cal_ids:
        frag, params = in_clause('calendarid', all_cal_ids)
        cur.execute(
            f"DELETE FROM oc_calendarobjects WHERE {frag} AND calendartype=0",
            params
        )
        log(f'[CALENDAR] Cleared existing events for {len(all_cal_ids)} calendar(s).')

    skipped_no_dates = 0
    skipped_build_error = 0

    # Build extra EXDATE map: for deleted recurring exceptions (cal_reference != 0),
    # collect the deleted occurrence timestamps keyed by the parent cal_id.
    extra_exdates_by_id = {}  # parent cal_id (str) -> list of datetime
    for event in events:
        cal_ref = v(event, 'cal_reference')
        cal_rec = v(event, 'cal_recurrence')
        if v(event, 'cal_deleted') and cal_ref and cal_ref != '0' and cal_rec and cal_rec != '0':
            exc_dt = ts_to_dt(cal_rec)
            if exc_dt:
                if cal_ref not in extra_exdates_by_id:
                    extra_exdates_by_id[cal_ref] = []
                extra_exdates_by_id[cal_ref].append(exc_dt)

    for event in events:
        # Skip deleted recurring exception events (they are handled as EXDATE in parent)
        cal_ref = v(event, 'cal_reference')
        if v(event, 'cal_deleted') and cal_ref and cal_ref != '0':
            skipped += 1
            continue
        # Import truly deleted (non-exception) events with STATUS:CANCELLED
        is_cancelled = bool(v(event, 'cal_deleted') and (not cal_ref or cal_ref == '0'))

        # Route event to owner's calendar
        cal_owner_str = event.get('cal_owner', '0') or '0'
        cal_id_for_event = owner_to_cal_id.get(cal_owner_str, default_cal_id)

        extra_exdates = extra_exdates_by_id.get(str(event.get('cal_id')), [])
        try:
            ical, uid = build_vevent(event, dates_by_id, repeats_by_id, users_by_id, accounts,
                                     egw_account_email=egw_account_email,
                                     egw_account_name=egw_account_name,
                                     extra_exdates=extra_exdates,
                                     is_cancelled=is_cancelled)
        except Exception as e:
            log(f'  [WARN] Could not build VEVENT for cal_id={event.get("cal_id")}: {e}')
            skipped += 1
            skipped_build_error += 1
            continue

        if not ical:
            skipped += 1
            skipped_no_dates += 1
            continue

        cal_id_egw = event.get('cal_id')
        uri = v(event, 'caldav_name') or f'{uid}.ics'
        etag = hashlib.md5(ical.encode(), usedforsecurity=False).hexdigest()
        size = len(ical.encode('utf-8'))

        date_rows = dates_by_id.get(cal_id_egw, [])
        main_date = date_rows[0] if date_rows else {}
        first_occ = int(v(main_date, 'cal_start') or 0)
        last_occ  = int(v(main_date, 'cal_end') or 0)

        if not DRY_RUN:
            # Track seen UIDs and URIs within this batch to handle duplicates from egroupware itself
            if not hasattr(import_calendar, '_seen_uids'):
                import_calendar._seen_uids = set()
                import_calendar._seen_uris = set()
            if uid in import_calendar._seen_uids:
                uid = f'{uid}-{cal_id_egw}'
            if uri in import_calendar._seen_uris:
                uri = f'egw-{cal_id_egw}-{uri}'
            import_calendar._seen_uids.add(uid)
            import_calendar._seen_uris.add(uri)

            cur.execute(
                '''INSERT INTO oc_calendarobjects
                   (calendardata, uri, calendarid, lastmodified, etag, size,
                    componenttype, firstoccurence, lastoccurence, uid, calendartype)
                   VALUES (%s, %s, %s, %s, %s, %s, 'VEVENT', %s, %s, %s, 0)''',
                (ical.encode('utf-8'), uri, cal_id_for_event, now_ts, etag, size,
                 first_occ, last_occ, uid)
            )
            if cal_id_for_event is not None:
                touched_cal_ids.add(cal_id_for_event)
        imported += 1

    if not DRY_RUN:
        if touched_cal_ids:
            # Bump synctoken once for EVERY calendar we wrote to (events may span
            # several). Guarded so an empty/all-skipped run does nothing — avoids
            # the previous UnboundLocalError / last-id-only bug.
            frag, params = in_clause('id', touched_cal_ids)
            cur.execute(f'UPDATE oc_calendars SET synctoken=synctoken+1 WHERE {frag}', params)
        # Reset seen-uid tracking for next run
        import_calendar._seen_uids = set()
        import_calendar._seen_uris = set()

    log(f'[CALENDAR] Imported {imported}, skipped {skipped} (recurring-exceptions={len(extra_exdates_by_id)}, no-dates={skipped_no_dates}, build-error={skipped_build_error}).')


# ---------------------------------------------------------------------------
# Step 6: Import Infolog notes into Touchpoint
# ---------------------------------------------------------------------------

# Map EGroupware infolog types to CRM note_type names (must match oc_touchpoint_note_types)
INFOLOG_TYPE_MAP = {
    'note':  'General',
    'phone': 'Call',
    'email': 'Email',
    'task':  'Task',
    'Fax':   'Call',
    'sms':   'General',
}

DEFAULT_NOTE_TYPES = [
    ('General', 'icon-note',      '#0082c9'),
    ('Call',    'icon-phone',     '#2ecc71'),
    ('Email',   'icon-mail',      '#9b59b6'),
    ('Task',    'icon-checkmark', '#e67e22'),
    ('Meeting', 'icon-calendar',  '#3498db'),
]


_HTML_BLOCK_TAGS = re.compile(r'<(?:br\s*/?\s*|/p|/div|/li|/tr|/h[1-6])[^>]*>', re.IGNORECASE)
_HTML_TAGS = re.compile(r'<[^>]+>')
_MULTI_NL = re.compile(r'\n{3,}')


def _clean_note_content(text: str) -> str:
    """Normalise EGroupware infolog content to markdown."""
    if not text:
        return text

    # Convert literal escape sequences to real characters first
    text = text.replace('\\r\\n', '\n').replace('\\n', '\n').replace('\\r', '\n')
    text = text.replace('\r\n', '\n').replace('\r', '\n')

    # If the text looks like HTML, convert to markdown
    if re.search(r'<[a-zA-Z][^>]*>', text):
        if _HTML2TEXT_AVAILABLE:
            text = _H2T.handle(text)
        else:
            # Fallback: convert block tags to newlines then strip
            text = _HTML_BLOCK_TAGS.sub('\n', text)
            text = _HTML_TAGS.sub('', text)
            text = html_unescape(text)

    # Collapse runs of 3+ blank lines to 2
    text = _MULTI_NL.sub('\n\n', text)
    return text.strip()


def _nc_data_dir():
    """Resolve the Nextcloud data directory (from detected config, else default)."""
    return (DB_CONFIG.get('datadir') if DB_CONFIG else None) or NC_DATA_DIR_DEFAULT

import functools

@functools.lru_cache(maxsize=1)
def _nc_uid_int():
    import pwd
    try:
        return pwd.getpwnam('www-data').pw_uid
    except KeyError:
        return -1

@functools.lru_cache(maxsize=1)
def _nc_gid_int():
    import grp
    try:
        return grp.getgrnam('www-data').gr_gid
    except KeyError:
        return -1


def _seed_global_note_types(cur):
    """Ensure the shared GLOBAL default note types exist and return a {name: id} map.

    Matches the app's NoteTypeService::seedDefaults model: ONE instance-wide set
    of defaults stored with an empty user_id and is_default = true. Every user can
    see and select them (NoteTypeMapper read scope) but no one owns them, so they
    are immutable — and crucially they are NOT duplicated per user. Imported notes
    reference these global ids. Idempotent (only inserts missing names); booleans
    bound as params for cross-DB portability."""
    cur.execute("SELECT name FROM oc_touchpoint_note_types WHERE user_id=%s AND is_default=%s", ('', True))
    existing = {r[0] for r in cur.fetchall()}
    for (name, icon, color) in DEFAULT_NOTE_TYPES:
        if name not in existing:
            cur.execute(
                """INSERT INTO oc_touchpoint_note_types (name, icon, color, user_id, is_default)
                   VALUES (%s, %s, %s, %s, %s)""",
                (name, icon, color, '', True)
            )
    cur.execute("SELECT id, name FROM oc_touchpoint_note_types WHERE user_id=%s AND is_default=%s", ('', True))
    return {r[1]: r[0] for r in cur.fetchall()}


def import_notes(egw, cur, egw_to_nc=None, egw_account_email=None, egw_account_name=None):
    log('[NOTES] Importing infolog entries into Touchpoint …')

    notes    = egw.get('egw_infolog')
    links    = egw.get('egw_links')
    contacts = egw.get('egw_addressbook')

    # Build attachment index: info_id (str) -> [(fs_id, filename, mime)]
    attachment_index = egw.build_infolog_attachment_index()

    if egw_to_nc is None:
        egw_to_nc = {}

    # Determine all NC users that will receive notes
    all_note_nc_users = set()
    for note in notes:
        owner = v(note, 'info_owner') or ''
        all_note_nc_users.add(_resolve_nc_user(owner, egw_to_nc))

    # Clear existing CRM notes for the users we're about to populate.
    # --no-wipe-notes skips this entirely; otherwise the wipe is SCOPED to the
    # imported users so other users' notes are never touched.
    if not DRY_RUN:
        if args.no_wipe_notes:
            log('[NOTES] --no-wipe-notes set: keeping existing CRM notes.')
        else:
            frag, params = in_clause('user_id', all_note_nc_users)
            # Delete note_contacts for the affected users' notes first
            # (no DB-level FKs), then the notes themselves.
            cur.execute(
                f'DELETE FROM oc_touchpoint_note_contacts WHERE note_id IN '
                f'(SELECT id FROM oc_touchpoint_notes WHERE {frag})',
                params
            )
            cur.execute(f'DELETE FROM oc_touchpoint_notes WHERE {frag}', params)
            log(f'[NOTES] Cleared existing CRM notes for {len(all_note_nc_users)} '
                f'imported user(s): {sorted(all_note_nc_users)}')

    # Build contact uid lookup: egw addressbook contact_id -> contact_uid
    contact_uid_by_id = {c.get('contact_id'): v(c, 'contact_uid') for c in contacts}

    # Build note→contact links from egw_links
    note_contacts = {}   # info_id -> list of contact_uid
    for link in links:
        if link.get('link_app1') == 'infolog' and link.get('link_app2') == 'addressbook':
            info_id = link.get('link_id1')
            ab_id   = link.get('link_id2')
            if link.get('deleted'):
                continue
            contact_uid = contact_uid_by_id.get(ab_id)
            if contact_uid:
                if info_id not in note_contacts:
                    note_contacts[info_id] = []
                note_contacts[info_id].append(contact_uid)

    # Seed the shared GLOBAL default note types once (user_id='', is_default=true),
    # matching the app's model, and map every imported note to those global ids.
    # (No per-user copies — that would duplicate the set the app seeds globally.)
    global_note_types = {} if DRY_RUN else _seed_global_note_types(cur)

    imported = 0
    skipped  = 0
    attach_imported = 0
    attach_skipped  = 0
    users_needing_scan = set()

    for note in notes:
        title = v(note, 'info_subject') or '(Kein Betreff)'

        info_id  = note.get('info_id')
        info_type = v(note, 'info_type') or 'note'
        info_owner = v(note, 'info_owner') or ''
        nc_uid = _resolve_nc_user(info_owner, egw_to_nc)

        type_name = INFOLOG_TYPE_MAP.get(info_type, 'General')
        if DRY_RUN:
            note_type_id = 1
        else:
            note_type_id = global_note_types.get(type_name) or global_note_types.get('General')
            if not note_type_id:
                log(f'  [WARN] no global note type resolved for "{type_name}"; skipping note {info_id}.')
                skipped += 1
                continue

        content = _clean_note_content(v(note, 'info_des') or '')
        # Add info_from as prefix if present
        info_from = v(note, 'info_from')
        if info_from:
            content = f'Von: {info_from}\n\n{content}' if content else f'Von: {info_from}'
        # Mark notes that were soft-deleted in EGroupware
        if v(note, 'info_status') == 'deleted':
            content = f'[In EGroupware gelöscht]\n\n{content}' if content else '[In EGroupware gelöscht]'

        # Append supplementary fields as structured metadata at end of content
        meta_lines = []
        priority_map = {'0': 'Niedrig', '1': None, '2': 'Hoch', '3': 'Dringend'}
        prio = priority_map.get(v(note, 'info_priority') or '1')
        if prio:
            meta_lines.append(f'Priorität: {prio}')
        status = v(note, 'info_status')
        if status and status not in ('', 'done', 'not-started'):
            meta_lines.append(f'Status: {status}')
        location = v(note, 'info_location')
        if location:
            meta_lines.append(f'Ort: {location}')
        enddate_ts = v(note, 'info_enddate')
        if enddate_ts:
            enddt = ts_to_dt(enddate_ts)
            if enddt:
                meta_lines.append(f'Fällig: {enddt.strftime("%d.%m.%Y")}')
        percent = v(note, 'info_percent')
        if percent and percent not in ('0', '', None):
            meta_lines.append(f'Fortschritt: {percent}%')
        if meta_lines:
            sep = '\n\n---\n' if content else ''
            content = content + sep + '\n'.join(meta_lines)

        # Timestamps — info_created is the actual creation time; info_startdate is task start
        created_ts = v(note, 'info_created') or v(note, 'info_startdate')
        mod_ts     = v(note, 'info_datemodified')
        created    = ts_to_dt(created_ts) or ts_to_dt(mod_ts) or datetime.now(tz=timezone.utc)
        updated    = ts_to_dt(mod_ts) or created

        # Creator / last-modifier display names
        creator_id  = v(note, 'info_creator') or v(note, 'info_owner')
        modifier_id = v(note, 'info_modifier') or creator_id
        created_by  = (egw_account_name or {}).get(creator_id) or (egw_account_email or {}).get(creator_id) or NC_USER
        updated_by  = (egw_account_name or {}).get(modifier_id) or (egw_account_email or {}).get(modifier_id) or created_by

        # The note's primary contact: first linked contact
        linked_contacts = note_contacts.get(info_id, [])
        primary_contact_uid = linked_contacts[0] if linked_contacts else ''

        # For CRM notes, contact_uid and addressbook_id are required
        # Resolve addressbook_id from oc_cards, fallback to admin's default addressbook
        if not DRY_RUN:
            ab_id = None
            if primary_contact_uid:
                cur.execute(
                    'SELECT addressbookid FROM oc_cards WHERE uid=%s LIMIT 1',
                    (primary_contact_uid,)
                )
                row = cur.fetchone()
                ab_id = row[0] if row else None

            if ab_id is None:
                # Fallback: use the NC user's own default addressbook (or admin's)
                fallback_uid = nc_uid or NC_USER
                cur.execute(
                    "SELECT id FROM oc_addressbooks WHERE principaluri=%s AND uri='contacts' LIMIT 1",
                    (f'principals/users/{fallback_uid}',)
                )
                row = cur.fetchone()
                if not row:
                    cur.execute(
                        "SELECT id FROM oc_addressbooks WHERE principaluri=%s LIMIT 1",
                        (f'principals/users/{NC_USER}',)
                    )
                    row = cur.fetchone()
                ab_id = row[0] if row else None

            if ab_id is None:
                skipped += 1
                continue

            # Insert note. is_pinned is bound as a parameter (not the SQL
            # keyword `false`) so SQLite, which has no boolean literal, works.
            note_db_id = insert_returning_id(
                cur,
                '''INSERT INTO oc_touchpoint_notes
                   (contact_uid, addressbook_id, note_type_id, title, content,
                    user_id, is_pinned, created_at, updated_at, created_by, updated_by)
                   VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)''',
                (primary_contact_uid, ab_id, note_type_id, title, content,
                 nc_uid, False,
                 created.replace(tzinfo=None),
                 updated.replace(tzinfo=None),
                 created_by,
                 updated_by)
            )

            # Insert additional linked contacts
            for contact_uid in linked_contacts:
                cur.execute(
                    'SELECT addressbookid FROM oc_cards WHERE uid=%s LIMIT 1',
                    (contact_uid,)
                )
                row = cur.fetchone()
                link_ab_id = row[0] if row else 0
                insert_ignore(
                    cur,
                    '''INSERT INTO oc_touchpoint_note_contacts (note_id, contact_uid, addressbook_id)
                       VALUES (%s, %s, %s)''',
                    (note_db_id, contact_uid, link_ab_id)
                )

            # Import file attachments for this note
            attachments = attachment_index.get(str(info_id), [])
            for (fs_id, filename, mime) in attachments:
                # Path-traversal guard: info_id is interpolated into the on-disk
                # path below, so it must be a plain integer. safe_name is already
                # sanitised; only info_id needs validating here.
                if not str(info_id).isdigit():
                    log(f'  [WARN] skipping attachment for non-numeric info_id={info_id!r}')
                    attach_skipped += 1
                    continue
                # Sanitize filename
                safe_name = re.sub(r'[^\w\s.\-()]', '_', filename).strip() or f'attachment_{fs_id}'
                # Target path within user's NC files
                rel_path = f'/CRM Anhänge/{info_id}/{safe_name}'
                nc_file_path = os.path.join(
                    _nc_data_dir(), nc_uid, 'files',
                    'CRM Anhänge', str(info_id), safe_name,
                )
                file_bytes = egw.read_sqlfs_file(fs_id)
                if file_bytes is None:
                    attach_skipped += 1
                    continue
                os.makedirs(os.path.dirname(nc_file_path), exist_ok=True)
                with open(nc_file_path, 'wb') as fh:
                    fh.write(file_bytes)
                # Fix ownership so NC (www-data) can read it
                try:
                    os.chown(nc_file_path, _nc_uid_int(), _nc_gid_int())
                except Exception:
                    pass
                # Register in oc_touchpoint_note_files (file_id=0, updated by occ files:scan later)
                insert_ignore(
                    cur,
                    '''INSERT INTO oc_touchpoint_note_files (note_id, file_id, file_path, user_id)
                       VALUES (%s, 0, %s, %s)''',
                    (note_db_id, rel_path, nc_uid)
                )
                users_needing_scan.add(nc_uid)
                attach_imported += 1

        imported += 1

    log(f'[NOTES] Imported {imported}, skipped {skipped}.')
    log(f'[ATTACHMENTS] Imported {attach_imported}, skipped {attach_skipped} (file content not in backup).')
    if attach_skipped:
        log('[ATTACHMENTS] Note: skipped attachments are referenced by egw_sqlfs but their '
            'file content is neither in the backup ZIP (sqlfs/) nor inline in '
            'egw_sqlfs.fs_content. EGroupware stores filesystem-backed VFS files under '
            'its files_dir; re-export the backup with the files directory included '
            '(or provide that directory) to import them.')
    return users_needing_scan


# ---------------------------------------------------------------------------
# Post-import: rebuild Nextcloud caches
# ---------------------------------------------------------------------------

def _occ(args, description):
    log(f'[OCC] {description} …')
    result = subprocess.run(
        ['php', OCC] + args,
        capture_output=True, text=True,
        env={**os.environ, 'HOME': '/var/www'}
    )
    if result.returncode != 0:
        log(f'  [WARN] {description} failed (non-fatal): {result.stderr.strip()[:200]}')
    else:
        out = result.stdout.strip()
        if out:
            log(f'  {out[:200]}')


def backfill_note_file_ids(users_needing_scan):
    """Populate touchpoint_note_files.file_id (left 0 at insert time) once `occ
    files:scan` has registered the imported attachments in the file cache, so
    the app can resolve attachments by id and not just display the filename.

    The cache path is built in Python and matched with a plain '=' (no
    vendor-specific concat / UPDATE-JOIN / UPSERT), so the matching logic itself
    is database-agnostic. It connects via the shared get_conn() and goes through
    the same paramstyle-normalising cursor wrapper, so it works on all backends."""
    if DRY_RUN or not users_needing_scan:
        return
    import unicodedata
    log('[POST] Backfilling touchpoint_note_files.file_id from the file cache …')
    try:
        conn = get_conn()
    except Exception as e:
        log(f'  [WARN] could not connect for file_id backfill (non-fatal): {e}')
        return
    updated = 0
    expected = 0
    cur = conn.cursor()
    try:
        for nc_uid in sorted(users_needing_scan):
            # Resolve the user's storage. Normal installs use 'home::<uid>';
            # object stores use 'object::user::<uid>'.
            cur.execute(
                "SELECT numeric_id FROM oc_storages WHERE id=%s OR id=%s",
                (f'home::{nc_uid}', f'object::user::{nc_uid}')
            )
            row = cur.fetchone()
            if not row:
                log(f'  [WARN] no storage found for {nc_uid}; leaving its attachments at file_id=0.')
                continue
            storage_id = row[0]
            cur.execute(
                "SELECT note_id, file_path FROM oc_touchpoint_note_files WHERE user_id=%s AND file_id=0",
                (nc_uid,)
            )
            rows = cur.fetchall()
            expected += len(rows)
            for note_id, file_path in rows:
                # touchpoint_note_files stores a user-folder-relative path; the file
                # cache stores it under 'files/<path>' without a leading slash.
                # Build it in Python and NFC-normalise (Nextcloud stores NFC) so
                # the lookup needs only '=' — portable and umlaut-safe.
                cache_path = unicodedata.normalize('NFC', 'files/' + file_path.lstrip('/'))
                cur.execute(
                    "SELECT fileid FROM oc_filecache WHERE storage=%s AND path=%s",
                    (storage_id, cache_path)
                )
                fr = cur.fetchone()
                if not fr:
                    continue
                cur.execute(
                    "UPDATE oc_touchpoint_note_files SET file_id=%s WHERE note_id=%s AND file_path=%s",
                    (fr[0], note_id, file_path)
                )
                updated += 1
        conn.commit()
        if updated < expected:
            log(f'  [WARN] linked {updated}/{expected} attachments; {expected - updated} still at '
                f'file_id=0 — was `occ files:scan` run and did the paths match?')
        else:
            log(f'  Updated {updated} attachment row(s) with real file ids.')
    except Exception as e:
        conn.rollback()
        log(f'  [WARN] file_id backfill failed (non-fatal): {e}')
    finally:
        cur.close()
        conn.close()


def _run_occ_post_import(users_needing_scan=None):
    log('[POST] Rebuilding Nextcloud caches …')
    # Fix missing CalDAV change records
    _occ(['dav:fix-missing-caldav-changes'],
         'Fix missing CalDAV changes')
    # Sync birthday calendar from imported contacts
    _occ(['dav:sync-birthday-calendar'],
         'Sync birthday calendar')
    # Rebuild the system addressbook (used for autocomplete)
    _occ(['dav:sync-system-addressbook'],
         'Sync system addressbook')
    # Scan files for users with imported attachments
    if users_needing_scan:
        for nc_uid in sorted(users_needing_scan):
            _occ(['files:scan', '--path', f'{nc_uid}/files/CRM Anhänge'],
                 f'Scan CRM Anhänge for {nc_uid}')
    log('[POST] Done.')


# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------

def main():
    # Resolve the database backend (CLI args > config.php > defaults) up front
    # so backup_database() and get_conn() both see DB_TYPE / DB_CONFIG.
    resolve_db_config()

    log('=' * 60)
    log('EGroupware → Nextcloud import')
    log(f'  Backup file : {BACKUP_FILE}')
    log(f'  NC user     : {NC_USER}')
    log(f'  DB type     : {DB_TYPE}')
    log(f'  DB name     : {DB_CONFIG.get("name")}')
    log(f'  Dry run     : {DRY_RUN}')
    log(f'  Only        : {args.only or "all"}')
    log(f'  Photos dir  : {args.photos_dir or "(none, ZIP SQLFS used if available)"}')
    log(f'  Import users: {args.import_users}')
    log(f'  Skip inactive:{args.skip_inactive}')
    log(f'  Helper acct : {args.helper_account or "(none, falls back to --nc-user)"}')
    log('=' * 60)

    # 1. Backup database
    if not DRY_RUN and not args.skip_backup:
        backup_database()

    # 2. Parse backup
    egw = EgroupwareBackup(BACKUP_FILE)
    egw.parse()

    if DRY_RUN:
        log('[DRY-RUN] Not writing to database. Summary above.')
        contacts_count = len([c for c in egw.get('egw_addressbook')
                               if v(c, 'n_fn', 'n_family', 'n_given', 'org_name')])
        events_count = len([e for e in egw.get('egw_cal')
                            if not (v(e, 'cal_deleted') and v(e, 'cal_reference') and v(e, 'cal_reference') != '0')])
        notes_count = len([n for n in egw.get('egw_infolog')
                           if v(n, 'info_status') != 'deleted' and v(n, 'info_subject')])
        log(f'  Contacts to import  : {contacts_count}')
        log(f'  Events to import    : {events_count}')
        log(f'  Notes to import     : {notes_count}')
        return

    # 3. Connect to database and import — everything in one transaction
    try:
        conn = get_conn()
    except Exception as e:
        log(f'[ERROR] Cannot connect to database: {e}')
        sys.exit(1)

    cur = conn.cursor()
    try:
        # Build user mapping (EGroupware account_id ↔ Nextcloud uid via email)
        egw_account_email, egw_account_name, egw_to_nc = build_user_map(egw, cur)

        # Optionally create users/groups from EGroupware accounts
        if args.import_users:
            log('[USERS] Creating users and groups from EGroupware accounts …')
            new_mappings = import_users_and_groups(
                egw, cur, egw_account_email, egw_account_name, args
            )
            egw_to_nc.update(new_mappings)

        # Build group name lookup for addressbook naming
        group_names = {
            a.get('account_id'): a.get('account_lid', '')
            for a in egw.get('egw_accounts', [])
            if a.get('account_type') == 'g'
        }

        if args.only in (None, 'contacts'):
            owner_to_ab_id, default_ab_id = ensure_all_addressbooks(
                egw, cur, egw_to_nc, group_names
            )
            import_contacts(egw, cur,
                            owner_to_ab_id=owner_to_ab_id,
                            default_ab_id=default_ab_id,
                            egw_to_nc=egw_to_nc)

        if args.only in (None, 'calendar'):
            owner_to_cal_id, default_cal_id = ensure_all_calendars(
                egw, cur, egw_to_nc,
                share_group=args.calendar_share_group,
            )
            import_calendar(egw, cur,
                            owner_to_cal_id=owner_to_cal_id,
                            default_cal_id=default_cal_id,
                            egw_account_email=egw_account_email,
                            egw_account_name=egw_account_name)

        users_needing_scan = set()
        if args.only in (None, 'notes'):
            users_needing_scan = import_notes(egw, cur,
                         egw_to_nc=egw_to_nc,
                         egw_account_email=egw_account_email,
                         egw_account_name=egw_account_name) or set()

        conn.commit()
        log('[DONE] Database transaction committed.')
    except Exception as e:
        conn.rollback()
        log(f'[ERROR] Import failed, entire transaction rolled back: {e}')
        import traceback
        traceback.print_exc()
        sys.exit(1)
    finally:
        cur.close()
        conn.close()

    # Post-import: rebuild Nextcloud caches via OCC, then link the scanned
    # attachment files back to their touchpoint_note_files rows.
    _run_occ_post_import(users_needing_scan)
    backfill_note_file_ids(users_needing_scan)


if __name__ == '__main__':
    main()
