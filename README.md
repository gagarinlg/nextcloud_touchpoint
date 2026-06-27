<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->

# Touchpoint

CRM-style notes attached to your Nextcloud address-book contacts.

Touchpoint lets you keep a timeline of interactions (calls, meetings, emails,
tasks, …) against each contact. Notes have a **type** (with colour and icon),
can be **pinned**, carry **file attachments**, render **Markdown**, and can be
**shared** with other users and groups.

## Features

- **Notes on contacts** — a per-contact timeline, plus an all-notes view.
- **Note types** — Call, Meeting, Email, Task, General, plus your own custom
  types with colours and icons (a single shared set of defaults).
- **Pin** important notes; **Markdown** bodies (sanitised via DOMPurify).
- **File attachments** from your Nextcloud Files.
- **Sharing** with users/groups, with per-recipient edit permission.
- **Contacts app integration** (when the Contacts app is installed):
  - a **Touchpoint panel** injected into the contact detail view, with inline
    note creation;
  - an **embedded contact card** shown next to a contact's notes in the app;
  - a **hover-menu entry** linking to a contact's notes.
- **Admin settings** — optional "all notes public" mode and default sharing.

## Requirements

- Nextcloud **32, 33 or 34**
- PHP **8.2–8.5**
- Recommended companion app: **Contacts** (enables the in-Contacts panel,
  embedded card, and hover-menu entry; the standalone view works without it)

There are **no runtime PHP dependencies** beyond Nextcloud itself
(`Sabre\VObject` ships with the server); `vendor/` holds dev tools only.

## Installation

This app is not yet on the App Store, so install it manually.

### From a release tarball (recommended)

```bash
make appstore                       # produces build/appstore/touchpoint.tar.gz
# on the server:
tar -xzf touchpoint.tar.gz -C /var/www/html/apps/
chown -R www-data:www-data /var/www/html/apps/touchpoint
sudo -u www-data php /var/www/html/occ app:enable touchpoint
```

### Deploy straight into a local Nextcloud

```bash
sudo make deploy NEXTCLOUD_APPS=/var/www/html/apps
sudo chown -R www-data:www-data /var/www/html/apps/touchpoint
sudo -u www-data php /var/www/html/occ app:enable touchpoint
```

`make deploy` builds the frontend and copies **only the runnable app** (no
`node_modules`, `.git`, tests or dev configs) into the apps directory as a real
folder — so the Nextcloud updater's "create backup" step stays small. **Do not**
symlink the development checkout into `apps/`: the updater follows the symlink
and tries to back up `node_modules`/`.git`, which breaks the backup step.

The app folder **must** be named `touchpoint` (it must match the app id).

## Development

```bash
make deps        # composer install + npm ci
make build       # build the frontend into js/ and css/
make lint        # eslint + phpstan
make test        # phpunit
make test-e2e    # playwright (needs a running Nextcloud at localhost)
```

`js/` and `css/` are build outputs and are **not** committed; run `make build`
(or `npm run build`) after changing anything in `src/`. After changing the app,
run `make deploy` to push it to your local Nextcloud.

### Layout

```
appinfo/     App manifest (info.xml) and routes.
lib/         PHP — Controllers, Services, Db (QBMapper), Migrations, Listeners.
src/         Vue 3 + Pinia frontend sources (built to js/ + css/ by Vite).
templates/   PHP templates served to the page.
l10n/        Translations.
tests/       PHPUnit unit tests.
e2e/         Playwright end-to-end specs.
import_egroupware.py   Optional one-off eGroupware → Touchpoint migration tool.
```

## Migrating from eGroupware

`import_egroupware.py` imports infolog notes, contacts and calendar from an
eGroupware backup. It is a standalone tool, not part of the running app — see
the comments at the top of the script for usage.

## License

AGPL-3.0-or-later. See [LICENSES/](LICENSES/); the project is
[REUSE](https://reuse.software/)-compliant.
