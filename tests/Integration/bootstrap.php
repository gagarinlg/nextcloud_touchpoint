<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Bootstrap for the integration test suite.
 *
 * Database engine strategy
 * ------------------------
 * PRIMARY: SQLite in-memory (pdo_sqlite) — self-contained, zero config, runs
 * in CI with no external database service.  pdo_sqlite has been confirmed
 * available on this host (php8.3-sqlite3 package installed).
 *
 * FALLBACK: PostgreSQL via a Unix-socket peer-auth connection to the NC
 * database, used only when pdo_sqlite is absent.
 *
 * SQLite LIKE limitation
 * ----------------------
 * SQLite LIKE is case-insensitive for ASCII only.  Integration tests must use
 * ASCII-only fixture terms.  Unicode case-insensitivity (e.g. German umlauts)
 * must be verified in T10 e2e against the real MySQL/PostgreSQL instance.
 * Predicate-isolation and result-scoping tests remain fully valid on SQLite.
 *
 * Environment skip guard
 * ----------------------
 * If neither pdo_sqlite nor pdo_pgsql is loaded, this bootstrap sets
 * $GLOBALS['integration_skip_reason'] and does NOT create any schema.
 * Individual integration test cases check this global in setUp() and call
 * $this->markTestSkipped() so the suite exits with a clean "S" rather than
 * an error.
 *
 * Usage
 * -----
 *   ./vendor/bin/phpunit --testsuite integration
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

// Load the application stubs (Nextcloud framework shims) so the test files
// can reference OCP\* classes without a full Nextcloud installation.
if (!class_exists(\OCP\AppFramework\Db\Entity::class)) {
    require_once __DIR__ . '/../stubs.php';
}

// We use a unique prefix for test tables so they are clearly identifiable and
// can be dropped without touching production tables.
if (!defined('TP_TEST_NOTES_TABLE')) {
    define('TP_TEST_NOTES_TABLE', 'tp_test_notes');
}

// ---------------------------------------------------------------------------
// PRIMARY: SQLite in-memory
// ---------------------------------------------------------------------------
if (extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // SQLite DDL — compatible dialect; no SERIAL (use INTEGER PRIMARY KEY
        // AUTOINCREMENT), no TIMESTAMP default functions.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . TP_TEST_NOTES_TABLE . ' (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_uid    TEXT NOT NULL DEFAULT \'\',
                addressbook_id INTEGER NOT NULL DEFAULT 0,
                note_type_id   INTEGER NOT NULL DEFAULT 0,
                title          TEXT NOT NULL DEFAULT \'\',
                content        TEXT,
                user_id        TEXT NOT NULL,
                is_pinned      INTEGER NOT NULL DEFAULT 0,
                created_at     TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at     TEXT NOT NULL DEFAULT (datetime(\'now\')),
                created_by     TEXT,
                updated_by     TEXT
            )'
        );

        $GLOBALS['integration_pdo']     = $pdo;
        $GLOBALS['integration_db_type'] = 'sqlite';

        // No shutdown cleanup needed — in-memory DB is gone when the process
        // exits.  Nothing to DROP or persist.
        return;

    } catch (\PDOException $e) {
        // SQLite in-memory should never fail, but fall through to the
        // PostgreSQL path rather than aborting the suite.
    }
}

// ---------------------------------------------------------------------------
// FALLBACK: PostgreSQL via a Unix-socket peer-auth connection
// ---------------------------------------------------------------------------
if (!extension_loaded('pdo_pgsql')) {
    $GLOBALS['integration_skip_reason'] =
        'Neither pdo_sqlite nor pdo_pgsql is loaded — integration suite '
        . 'requires at least one of them.  Install php-sqlite3 (preferred) '
        . 'or php-pgsql.';
    return;
}

// Read connection parameters from the Nextcloud config if available so the
// bootstrap works without hard-coding credentials.  Fall back to sensible
// defaults that match the development/CI environment (peer auth on Unix socket).
$ncConfig = [];
$ncConfigFile = '/var/www/html/config/config.php';
if (is_file($ncConfigFile)) {
    // The NC config defines $CONFIG; pull it without polluting the test namespace.
    $ncConfig = (static function () use ($ncConfigFile): array {
        $CONFIG = [];
        include $ncConfigFile;
        return $CONFIG;
    })();
}

$dbHost     = $ncConfig['dbhost']     ?? '/var/run/postgresql';
$dbName     = $ncConfig['dbname']     ?? 'nextcloud';
$dbPassword = $ncConfig['dbpassword'] ?? '';
$dbType     = $ncConfig['dbtype']     ?? 'pgsql';

// For Unix-socket peer authentication the PostgreSQL role that must exist is
// named after the OS user running the process (i.e. the developer or CI
// runner), not the Nextcloud web server user (www-data).  Override the NC
// config's dbuser when connecting via a socket path so peer auth succeeds.
// The database server uses pg_hba.conf "peer" entries which compare the
// role name in the connection request against the kernel-reported OS username;
// passing 'www-data' while running as 'gagarin' would trigger FATAL: Peer
// authentication failed.
$isUnixSocket = str_starts_with($dbHost, '/');
$dbUser = ($isUnixSocket && ($dbType === 'pgsql' || $dbType === 'postgresql'))
    ? get_current_user()
    : ($ncConfig['dbuser'] ?? get_current_user());

// Fallback only supports PostgreSQL and MySQL; skip gracefully for other types.
if ($dbType !== 'pgsql' && $dbType !== 'postgresql') {
    if (!extension_loaded('pdo_mysql') || $dbType !== 'mysql') {
        $GLOBALS['integration_skip_reason'] =
            "Nextcloud DB type '{$dbType}' is not supported by the integration "
            . 'bootstrap fallback (only pgsql/mysql).';
        return;
    }
}

// Build DSN.  NC stores the Unix socket path in dbhost when it starts with '/'.
if ($dbType === 'pgsql' || $dbType === 'postgresql') {
    if (str_starts_with($dbHost, '/')) {
        $dsn = "pgsql:host={$dbHost};dbname={$dbName}";
    } else {
        $port = $ncConfig['dbport'] ?? '5432';
        $dsn = "pgsql:host={$dbHost};port={$port};dbname={$dbName}";
    }
    $pdoUser     = $dbUser;
    $pdoPassword = $dbPassword;
} else {
    $port = $ncConfig['dbport'] ?? '3306';
    $dsn = "mysql:host={$dbHost};port={$port};dbname={$dbName};charset=utf8mb4";
    $pdoUser     = $dbUser;
    $pdoPassword = $dbPassword;
}

try {
    $pdo = new PDO($dsn, $pdoUser, $pdoPassword, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    $GLOBALS['integration_skip_reason'] =
        'Cannot connect to the integration database: ' . $e->getMessage() . '. '
        . 'Ensure the database is running and the connecting OS user has a matching role. '
        . 'On this host: sudo -u postgres psql -c "CREATE ROLE gagarin LOGIN;" '
        . 'and grant CONNECT on the nextcloud database.';
    return;
}

// Drop any leftover table from a previous incomplete run.
$pdo->exec('DROP TABLE IF EXISTS ' . TP_TEST_NOTES_TABLE);

if ($dbType === 'pgsql' || $dbType === 'postgresql') {
    $pdo->exec(
        'CREATE TABLE ' . TP_TEST_NOTES_TABLE . ' (
            id             SERIAL PRIMARY KEY,
            contact_uid    VARCHAR(255) NOT NULL DEFAULT \'\',
            addressbook_id INTEGER NOT NULL DEFAULT 0,
            note_type_id   INTEGER NOT NULL DEFAULT 0,
            title          VARCHAR(255) NOT NULL DEFAULT \'\',
            content        TEXT,
            user_id        VARCHAR(64) NOT NULL,
            is_pinned      BOOLEAN NOT NULL DEFAULT FALSE,
            created_at     TIMESTAMP NOT NULL DEFAULT NOW(),
            updated_at     TIMESTAMP NOT NULL DEFAULT NOW(),
            created_by     VARCHAR(64),
            updated_by     VARCHAR(64)
        )'
    );
} else {
    // MySQL/MariaDB DDL variant.
    $pdo->exec(
        'CREATE TABLE ' . TP_TEST_NOTES_TABLE . ' (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            contact_uid    VARCHAR(255) NOT NULL DEFAULT \'\',
            addressbook_id INT NOT NULL DEFAULT 0,
            note_type_id   INT NOT NULL DEFAULT 0,
            title          VARCHAR(255) NOT NULL DEFAULT \'\',
            content        TEXT,
            user_id        VARCHAR(64) NOT NULL,
            is_pinned      TINYINT(1) NOT NULL DEFAULT 0,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by     VARCHAR(64),
            updated_by     VARCHAR(64)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

$GLOBALS['integration_pdo']     = $pdo;
$GLOBALS['integration_db_type'] = $dbType;

// Register a shutdown function to clean up test tables when the suite exits,
// so a crashed run does not leave orphan tables for the next run to find.
register_shutdown_function(static function () use ($pdo): void {
    try {
        $pdo->exec('DROP TABLE IF EXISTS ' . TP_TEST_NOTES_TABLE);
    } catch (\Throwable) {
        // Best-effort cleanup — ignore errors on shutdown.
    }
});
