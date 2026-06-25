<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Provide stubs for Nextcloud framework classes used in tests
// when running outside the Nextcloud environment.
if (!class_exists(\OCP\AppFramework\Db\Entity::class)) {
    require_once __DIR__ . '/stubs.php';
}
