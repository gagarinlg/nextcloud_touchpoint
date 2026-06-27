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

// ContactController parses vCard PHOTO properties with Sabre\VObject, which
// Nextcloud bundles at runtime under its 3rdparty/ tree. The unit-test
// autoloader does not see that tree, so load the bundled autoloader when the
// VObject Reader is not already available. This lets the photo-parsing tests
// exercise the real decoder without adding a composer dependency just for tests.
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
