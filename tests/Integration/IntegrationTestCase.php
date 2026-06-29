<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Base class for integration tests that execute real SQL queries.
 *
 * Extend this class instead of PHPUnit\Framework\TestCase for any test that
 * needs a real database connection.  The class ensures the integration
 * bootstrap has been loaded exactly once and skips the test (with a
 * descriptive message) when the database is unavailable.
 */

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase {

    protected PDO $pdo;
    protected string $dbType;

    /**
     * @before
     * Runs before every test method.  Ensures the bootstrap has been executed,
     * then either injects the PDO connection or skips with a documented reason.
     */
    protected function setUpIntegration(): void {
        // Load integration bootstrap exactly once across the entire suite run.
        // bootstrap.php sets $GLOBALS['integration_pdo'] on success, or
        // $GLOBALS['integration_skip_reason'] when the environment is unsuitable.
        if (!isset($GLOBALS['integration_pdo']) && !isset($GLOBALS['integration_skip_reason'])) {
            require_once __DIR__ . '/bootstrap.php';
        }

        if (isset($GLOBALS['integration_skip_reason'])) {
            $this->markTestSkipped($GLOBALS['integration_skip_reason']);
        }

        $this->pdo    = $GLOBALS['integration_pdo'];
        $this->dbType = $GLOBALS['integration_db_type'] ?? 'pgsql';
    }
}
