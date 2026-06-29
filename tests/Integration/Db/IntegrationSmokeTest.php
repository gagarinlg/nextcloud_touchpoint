<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Smoke test that verifies the integration bootstrap wired a real database
 * connection and created the touchpoint_notes test table.
 *
 * This test is intentionally minimal.  The substantive predicate-isolation
 * test (cross-user note scoping via NoteMapper::searchAccessiblePage()) is
 * implemented in T8 (NoteMapperSearchTest) once the mapper method exists.
 *
 * Primary engine: SQLite in-memory (pdo_sqlite — confirmed available on this
 * host via php8.3-sqlite3).  Self-contained; no external DB service required in
 * CI.  Fallback: PostgreSQL via Unix-socket peer-auth connection.
 * SQLite LIKE is case-insensitive for ASCII only; predicate-isolation tests are
 * fully valid on SQLite.  Unicode case-sensitivity (e.g. German umlauts) is
 * deferred to T10 e2e against MySQL/PostgreSQL.
 */

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Integration\Db;

use OCA\Touchpoint\Tests\Integration\IntegrationTestCase;

class IntegrationSmokeTest extends IntegrationTestCase {

    /** @before */
    protected function setUp(): void {
        $this->setUpIntegration();
    }

    /**
     * Verify the test table exists and accepts basic INSERT/SELECT/DELETE.
     */
    public function testTableIsWritable(): void {
        $table = \TP_TEST_NOTES_TABLE;

        // Insert a probe row.
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table}
                (contact_uid, note_type_id, title, user_id)
             VALUES (:uid, :type, :title, :user)"
        );
        $stmt->execute([
            ':uid'   => 'smoke-test-contact',
            ':type'  => 1,
            ':title' => 'smoke-test-note',
            ':user'  => 'smoke-test-user',
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->assertGreaterThan(0, $id, 'Inserted row must have a positive auto-increment id');

        // Verify the row is readable back.
        $selectStmt = $this->pdo->prepare("SELECT title, user_id FROM {$table} WHERE id = ?");
        $selectStmt->execute([$id]);
        $row = $selectStmt->fetch();

        $this->assertSame('smoke-test-note', $row['title']);
        $this->assertSame('smoke-test-user', $row['user_id']);

        // Clean up.
        $deleteStmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id = ?");
        $deleteStmt->execute([$id]);

        // Verify deleted.
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS cnt FROM {$table} WHERE id = ?");
        $countStmt->execute([$id]);
        $gone = $countStmt->fetchColumn();
        $this->assertSame('0', (string) $gone);
    }

    /**
     * Verify that user-scoped SELECT returns only the correct user's rows —
     * a minimal form of predicate isolation before T8's full mapper test.
     *
     * Two rows are inserted for different users; a WHERE user_id = userA filter
     * must return exactly one row and exclude userB's note.
     */
    public function testUserScopedSelectExcludesOtherUser(): void {
        $table = \TP_TEST_NOTES_TABLE;

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table}
                (contact_uid, note_type_id, title, user_id)
             VALUES (:uid, :type, :title, :user)"
        );

        // Insert userA note.
        $stmt->execute([':uid' => 'c1', ':type' => 1, ':title' => 'note-for-userA', ':user' => 'userA-smoke']);
        $idA = (int) $this->pdo->lastInsertId();

        // Insert userB note with a distinctive title.
        $stmt->execute([':uid' => 'c2', ':type' => 1, ':title' => 'userB-secret-note-smoke', ':user' => 'userB-smoke']);
        $idB = (int) $this->pdo->lastInsertId();

        try {
            // Query scoped to userA only.
            $rows = $this->pdo
                ->query(
                    "SELECT title FROM {$table}
                     WHERE user_id = 'userA-smoke'"
                )
                ->fetchAll();

            $titles = array_column($rows, 'title');

            $this->assertContains('note-for-userA', $titles, 'userA note must be visible to userA query');
            $this->assertNotContains('userB-secret-note-smoke', $titles, 'userB note must NOT appear in userA-scoped query');
        } finally {
            // Clean up both rows regardless of assertion outcome.
            $cleanStmt = $this->pdo->prepare("DELETE FROM {$table} WHERE id IN (?, ?)");
            $cleanStmt->execute([$idA, $idB]);
        }
    }
}
