<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Provide stubs for Nextcloud framework classes used in tests
// when running outside the Nextcloud environment.
if (!class_exists(\OCP\AppFramework\Db\Entity::class)) {
    require_once __DIR__ . '/stubs.php';
}

// ContactController parses vCard PHOTO properties with Sabre\VObject. At runtime
// Nextcloud provides it from its bundled 3rdparty/ tree; for tests it comes from
// the sabre/vobject require-dev dependency (so CI, which has no Nextcloud install,
// can exercise the real decoder). As a last resort — e.g. running the suite inside
// a real Nextcloud without `composer install` — fall back to the bundled tree.
if (!class_exists(\Sabre\VObject\Reader::class)) {
    foreach ([
        getenv('NEXTCLOUD_3RDPARTY_AUTOLOAD') ?: '',
        '/var/www/html/3rdparty/autoload.php',
        __DIR__ . '/../../../3rdparty/autoload.php',
    ] as $autoload) {
        if ($autoload !== '' && is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }
}
