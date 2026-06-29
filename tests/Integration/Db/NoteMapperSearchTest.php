<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Equivalent-SQL semantics tests for the NoteMapper search predicate.
 *
 * What this suite IS — and what it is NOT
 * ---------------------------------------
 * The security-critical cases in this suite DO drive the SHIPPED
 * OCA\Touchpoint\Db\NoteMapper::searchAccessiblePage(): they construct a real
 * NoteMapper (via the IntegrationNoteMapper subclass, whose only override is
 * generic row hydration) on a PDO-backed IDBConnection adapter, call the
 * production method, and assert on the rows that come back from a live engine.
 * Because the adapter (PdoQueryBuilder/PdoExpressionBuilder) knows only how to
 * render eq/in/iLike/orX/andX/where/andWhere into SQL — and NOTHING about
 * visibility-vs-search semantics — the "(visibility) AND (search)"
 * parenthesisation that isolates users is produced by the production mapper, not
 * by the test. A regression that flattened andWhere($search) to orWhere($search),
 * collapsed the two orX groups, or dropped a predicate would render to different
 * SQL through the adapter and FAIL these tests. See runRealMapperSearch().
 *
 * A second set of cases (execSearchAccessible()) runs a hand-written SQL
 * statement with the same structure on the engine. Those remain as a readable,
 * engine-level cross-check of the expected SQL shape and of LIKE-metacharacter
 * behaviour; they are NOT the leak proof on their own — the real-mapper cases are.
 * Additional production-method regression guards live in
 * tests/Unit/Db/NoteMapperSearchTest.php (predicate composition) and
 * e2e/search.spec.js (full HTTP -> NoteService -> NoteMapper path).
 *
 * Engine and fixture notes
 * ------------------------
 * Primary engine: SQLite in-memory (pdo_sqlite, self-contained in CI).
 * Fallback engine: PostgreSQL (peer-auth Unix socket).
 *
 * SQLite LIKE is case-insensitive for ASCII only; this suite uses ASCII-only
 * fixture terms. Unicode case-sensitivity (e.g. German umlauts) must be verified
 * in e2e against the real MySQL/PostgreSQL instance.
 *
 * Wildcard-escape fidelity: the OCP ExpressionBuilder emits a plain LIKE/ILIKE
 * with NO `ESCAPE` clause, and SQLite has no default LIKE escape character, so a
 * backslash-escaped `%`/`_` is NOT neutralised on SQLite (it IS on MySQL/PG,
 * which honour backslash as the default escape). To stay faithful to what the
 * real iLike() generates, execSearchAccessible() deliberately emits NO ESCAPE
 * clause — see testLikeMetacharacterBehaviourMatchesEngine().
 */

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Integration\Db;

use OCA\Touchpoint\Tests\Integration\IntegrationTestCase;

// The PDO-backed query-builder adapter + IntegrationNoteMapper live in a single
// helper file holding several classes (so they read as one cohesive shim), which
// PSR-4 cannot autoload. Require it explicitly.
require_once __DIR__ . '/PdoQueryBuilder.php';

/**
 * Equivalent-SQL semantics tests for the NoteMapper search predicate shape.
 *
 * Each test method inserts its own fixture rows and cleans them up in a
 * finally block, so the tests are independent of each other and do not
 * depend on the order in which PHPUnit executes them.
 */
class NoteMapperSearchTest extends IntegrationTestCase {

    /** @before */
    protected function setUp(): void {
        $this->setUpIntegration();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Insert a single note row and return its auto-generated id.
     *
     * Uses ASCII-only values for title and content so the tests run correctly
     * on SQLite, where LIKE is case-insensitive for ASCII only.
     *
     * @param string      $userId     owner of the note
     * @param string      $title      note title (ASCII only for SQLite compat)
     * @param string|null $content    note body (ASCII only for SQLite compat)
     * @param string      $contactUid contact UID (may be empty)
     * @return int        the auto-generated note id
     */
    private function insertNote(
        string $userId,
        string $title,
        ?string $content = null,
        string $contactUid = '',
    ): int {
        $table = TP_TEST_NOTES_TABLE;

        $stmt = $this->pdo->prepare(
            "INSERT INTO {$table}
                (user_id, title, content, contact_uid, note_type_id)
             VALUES (:user, :title, :content, :uid, :type)"
        );
        $stmt->execute([
            ':user'    => $userId,
            ':title'   => $title,
            ':content' => $content,
            ':uid'     => $contactUid,
            ':type'    => 1,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Execute SQL with the SAME STRUCTURE that NoteMapper::searchAccessiblePage()
     * generates: visibility predicate (user_id = $userId OR id IN ($sharedIds))
     * ANDed with search predicate (title LIKE $pattern OR content LIKE $pattern).
     *
     * Fidelity to the real query path: production buildLikePattern() runs the term
     * through IDBConnection::escapeLikeParameter() (backslash-escapes %, _, \) and
     * then builds the predicate with iLike(), which the OCP ExpressionBuilder emits
     * as a plain LIKE/ILIKE with NO `ESCAPE` clause. This helper mirrors BOTH halves
     * faithfully: it backslash-escapes the metacharacters exactly like
     * escapeLikeParameter(), and it emits NO ESCAPE clause. The consequence — the
     * very point of the fidelity — is that a % or _ in the term is neutralised only
     * on engines whose LIKE honours backslash as the default escape (MySQL, PostgreSQL,
     * the supported production targets) and stays an active wildcard on SQLite, which
     * has no default LIKE escape. See testLikeMetacharacterBehaviourMatchesEngine().
     *
     * LIKE rather than ILIKE is used because SQLite does not support ILIKE; the
     * pattern is lowercased and the comparison columns are lowercased via LOWER()
     * to achieve case-insensitive matching on SQLite. PostgreSQL supports ILIKE
     * natively; the fallback path uses it for fidelity.
     *
     * @param string $userId     the requesting user
     * @param int[]  $sharedIds  note ids explicitly shared with the user
     * @param string $term       the search term (ASCII only for SQLite compat)
     * @return array[]           rows returned: each is ['id' => int, 'title' => string, ...]
     */
    private function execSearchAccessible(
        string $userId,
        array $sharedIds,
        string $term,
    ): array {
        $table = TP_TEST_NOTES_TABLE;

        // Mirror buildLikePattern(): backslash-escape %, _ and \ exactly as
        // IDBConnection::escapeLikeParameter() does. No ESCAPE clause is added
        // below, matching iLike() — so this backslash is honoured only where the
        // engine treats it as the default LIKE escape (MySQL/PG), not on SQLite.
        $escaped = str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $term,
        );
        $pattern = '%' . $escaped . '%';

        // Build a safe IN clause for shared ids.  Empty list resolves to the
        // always-false predicate 1=0 so the visibility predicate degrades to
        // "user_id = :uid" only — matching NoteMapper's behaviour with an
        // empty $sharedIds array.
        if (empty($sharedIds)) {
            $inClause = '1=0';
            $inParams = [];
        } else {
            $placeholders = implode(', ', array_fill(0, count($sharedIds), '?'));
            $inClause     = "id IN ({$placeholders})";
            $inParams     = array_values($sharedIds);
        }

        // SQLite does not support ILIKE; simulate case-insensitive ASCII match
        // via LOWER().  PostgreSQL's ILIKE is used when available.
        if ($this->dbType === 'sqlite') {
            $likeOp       = 'LIKE';
            $titleExpr    = 'LOWER(title)';
            $contentExpr  = 'LOWER(content)';
            $lowerPattern = strtolower($pattern);
        } else {
            // PostgreSQL: native ILIKE.
            // MySQL: LIKE (case-insensitive by default for utf8mb4_general_ci).
            $likeOp       = ($this->dbType === 'pgsql' || $this->dbType === 'postgresql')
                ? 'ILIKE' : 'LIKE';
            $titleExpr    = 'title';
            $contentExpr  = 'content';
            $lowerPattern = $pattern;
        }

        // The critical structural property: visibility and search are two
        // SEPARATE parenthesised groups joined by AND.
        // Correct:   (user_id = :uid OR id IN (...)) AND (title ILIKE :p OR content ILIKE :p)
        // Incorrect: user_id = :uid OR id IN (...) OR title ILIKE :p OR content ILIKE :p
        // (The latter would return every shared note regardless of term match,
        // and every note matching the term regardless of ownership.)
        $sql = <<<SQL
            SELECT id, title, content, user_id
            FROM {$table}
            WHERE (user_id = ? OR {$inClause})
              AND ({$titleExpr} {$likeOp} ? OR {$contentExpr} {$likeOp} ?)
            ORDER BY id
            SQL;

        // Parameters: userId, then shared-id placeholders, then two pattern binds.
        $params = array_merge([$userId], $inParams, [$lowerPattern, $lowerPattern]);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Drive the SHIPPED NoteMapper::searchAccessiblePage() against the live
     * engine and return the matched note titles.
     *
     * Unlike execSearchAccessible(), this does NOT hand-build any SQL: it
     * constructs the production mapper on a PDO-backed IDBConnection adapter and
     * lets searchAccessiblePage() compose the query. The visibility/search
     * parenthesisation is therefore the real one. A regression in the mapper's
     * WHERE structure (flat-OR, dropped predicate, wrong column) fails here.
     *
     * @param string $userId    the requesting user
     * @param int[]  $sharedIds note ids explicitly shared with the user
     * @param string $term      the search term (ASCII only for SQLite compat)
     * @return string[]         titles of the returned notes
     */
    private function runRealMapperSearch(string $userId, array $sharedIds, string $term): array {
        $db     = new PdoDbConnection($this->pdo, $this->dbType);
        $mapper = new IntegrationNoteMapper($db);

        $notes = $mapper->searchAccessiblePage($userId, $sharedIds, $term, 50, 0);

        return array_map(static fn ($n) => $n->getTitle(), $notes);
    }

    // -----------------------------------------------------------------------
    // REAL-MAPPER predicate-isolation tests — these drive the shipped
    // NoteMapper::searchAccessiblePage() so a WHERE-clause regression fails.
    // -----------------------------------------------------------------------

    /**
     * REAL-MAPPER cross-user-leak proof (the central security claim).
     *
     * userA searches for a term that appears only in userB's note, driving the
     * production searchAccessiblePage(). Expected: empty — userA cannot see
     * userB's note. A flat-OR mis-parenthesisation in the mapper would surface
     * the note here and fail the test.
     */
    public function testRealMapperUserBNoteNotReturnedWhenSearchingAsUserA(): void {
        $idB = $this->insertNote('userB', 'userB-secret-note', 'private content of userB');

        try {
            $titles = $this->runRealMapperSearch('userA', [], 'userB-secret');

            $this->assertNotContains(
                'userB-secret-note',
                $titles,
                'Production searchAccessiblePage() must NOT leak userB note to userA',
            );
            $this->assertCount(
                0,
                $titles,
                'No results expected: the only matching note belongs to userB, not userA',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    /**
     * REAL-MAPPER positive case: userA's own matching note IS returned by the
     * production method.
     */
    public function testRealMapperUserANoteReturnedWhenSearchingAsUserA(): void {
        $idA = $this->insertNote('userA', 'my-private-note', 'some interesting content');

        try {
            $titles = $this->runRealMapperSearch('userA', [], 'my-private');

            $this->assertContains(
                'my-private-note',
                $titles,
                'Production searchAccessiblePage() must return userA own matching note',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idA]);
        }
    }

    /**
     * REAL-MAPPER AND-semantics proof: a note explicitly shared with userA must
     * NOT appear when it does not match the term. This is the exact regression a
     * flat-OR (visibility OR search) would introduce — the shared note would
     * return regardless of term. Driving the production method makes the
     * andWhere($search) composition load-bearing for this assertion.
     */
    public function testRealMapperSharedNoteWithNonMatchingTermIsExcluded(): void {
        $idB = $this->insertNote('userB', 'no-keyword-here', 'also-no-keyword');

        try {
            // $idB IS shared with userA, but the term is absent from the note.
            $titles = $this->runRealMapperSearch('userA', [$idB], 'findme-term');

            $this->assertNotContains(
                'no-keyword-here',
                $titles,
                'Shared-but-non-matching note must be excluded by the production '
                . '(visibility) AND (search) predicate — a flat OR would leak it',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    /**
     * REAL-MAPPER shared-note positive case: a matching note in sharedIds IS
     * returned, proving the OR(user_id | IN(sharedIds)) branch is wired in the
     * production method.
     */
    public function testRealMapperSharedNoteAppearsInSearch(): void {
        $idB = $this->insertNote('userB', 'shared-with-userA', 'shared-keyword-value');

        try {
            $titles = $this->runRealMapperSearch('userA', [$idB], 'shared-keyword');

            $this->assertContains(
                'shared-with-userA',
                $titles,
                'Explicitly shared, matching note must be returned by the production method',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    /**
     * REAL-MAPPER metacharacter case: a literal '%' term must never match-all
     * through the production buildLikePattern()/escapeLikeParameter() path. The
     * decoy (no literal '%' or backslash) must be absent on every engine.
     */
    public function testRealMapperLikeMetacharacterDoesNotMatchAllRows(): void {
        $idDecoy = $this->insertNote('userA', 'regular-note-no-percent', 'ordinary body text');

        try {
            $titles = $this->runRealMapperSearch('userA', [], '%');

            $this->assertNotContains(
                'regular-note-no-percent',
                $titles,
                "A literal '%' term must never match all rows through the production path",
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idDecoy]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: cross-user note does NOT appear in another user's search results
    //       (predicate-isolation — the mandatory T8 test)
    // -----------------------------------------------------------------------

    /**
     * PREDICATE-ISOLATION TEST — mandatory per T8.
     *
     * userA searches for a term that appears only in userB's note.
     * Expected: result is empty — userA cannot see userB's note.
     *
     * This is the test that mock-based unit tests CANNOT provide: mocks verify
     * that the query builder is called with the right arguments, but only real
     * SQL execution proves that a mis-parenthesised WHERE clause does not leak
     * notes across users.
     *
     * SQLite LIKE is case-insensitive for ASCII only; this test uses the
     * ASCII-only term 'userB-secret' to ensure it works on SQLite.  Unicode
     * case-sensitivity must be verified in T10 e2e against MySQL/PostgreSQL.
     */
    public function testUserBNoteNotReturnedWhenSearchingAsUserA(): void {
        $idB = $this->insertNote('userB', 'userB-secret-note', 'private content of userB');

        try {
            // userA has no shared ids — query scope is only "user_id = 'userA'".
            $results = $this->execSearchAccessible('userA', [], 'userB-secret');

            $titles = array_column($results, 'title');

            $this->assertNotContains(
                'userB-secret-note',
                $titles,
                'userB note must NOT appear in a search scoped to userA (predicate-isolation failure)',
            );
            $this->assertEmpty(
                $results,
                'No results expected: the only matching note belongs to userB, not userA',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: userA's own notes ARE returned in her own search
    // -----------------------------------------------------------------------

    /**
     * Positive case: userA's own note IS returned when userA searches.
     */
    public function testUserANoteReturnedWhenSearchingAsUserA(): void {
        $idA = $this->insertNote('userA', 'my-private-note', 'some interesting content');

        try {
            $results = $this->execSearchAccessible('userA', [], 'my-private');

            $titles = array_column($results, 'title');

            $this->assertContains(
                'my-private-note',
                $titles,
                'userA note must appear in userA search results',
            );
            $this->assertCount(1, $results, 'Exactly one matching note expected');
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idA]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: empty sharedIds list — query still valid, returns only owned notes
    // -----------------------------------------------------------------------

    /**
     * Empty sharedIds: the visibility predicate degrades to "user_id = :uid"
     * only (no IN clause).  The query must still execute without error and
     * return only the requesting user's matching notes.
     */
    public function testEmptySharedIdsQueryIsValid(): void {
        $idA = $this->insertNote('userA', 'searchable-term-here', null);
        $idB = $this->insertNote('userB', 'searchable-term-here', null);

        try {
            // userA searches with no sharedIds — only userA's row should appear.
            $results = $this->execSearchAccessible('userA', [], 'searchable-term');

            $userIds = array_column($results, 'user_id');

            $this->assertNotContains(
                'userB',
                $userIds,
                'userB row must not appear in userA-scoped search with empty sharedIds',
            );
            $this->assertContains(
                'userA',
                $userIds,
                'userA own row must appear in the results',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id IN (?, ?)');
            $stmt->execute([$idA, $idB]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: content match — iLike on content column works
    // -----------------------------------------------------------------------

    /**
     * Verify the search predicate covers the content column, not only the title.
     * The fixture note has a plain title but the search term appears only in
     * content.  userA must find her own note via content search.
     */
    public function testContentColumnIsSearched(): void {
        $idA = $this->insertNote('userA', 'plain-title', 'findable-content-keyword');

        try {
            $results = $this->execSearchAccessible('userA', [], 'findable-content');

            $titles = array_column($results, 'title');

            $this->assertContains(
                'plain-title',
                $titles,
                'Note must be returned when search term appears in content, not title',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idA]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: shared note IS returned for the sharing user
    // -----------------------------------------------------------------------

    /**
     * When userB's note id is in the sharedIds list (it was explicitly shared),
     * userA's search must include it.
     *
     * This verifies the OR(user_id | IN(sharedIds)) logic is correctly wired.
     */
    public function testSharedNoteAppearsInSearch(): void {
        // Note owned by userB, content contains the search term.
        $idB = $this->insertNote('userB', 'shared-with-userA', 'shared-keyword-value');

        try {
            // userA has $idB in her sharedIds — explicitly shared.
            $results = $this->execSearchAccessible('userA', [$idB], 'shared-keyword');

            $titles = array_column($results, 'title');

            $this->assertContains(
                'shared-with-userA',
                $titles,
                'Explicitly shared note must appear in userA search when id is in sharedIds',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: non-matching shared note does NOT appear (AND between predicates)
    // -----------------------------------------------------------------------

    /**
     * Critical AND-semantics test: a note in the sharedIds list must NOT appear
     * if it does not match the search term.
     *
     * A flat-OR mis-parenthesisation (user_id=A OR id IN(...) OR title LIKE ...)
     * would return ALL shared notes regardless of the search term.  This test
     * catches that bug.
     */
    public function testSharedNoteWithNonMatchingTermIsExcluded(): void {
        // Note owned by userB, shared with userA — but title/content do NOT
        // contain the search term.
        $idB = $this->insertNote('userB', 'no-keyword-here', 'also-no-keyword');

        try {
            // userA has $idB in sharedIds but the search term is absent from the note.
            $results = $this->execSearchAccessible('userA', [$idB], 'findme-term');

            $titles = array_column($results, 'title');

            $this->assertNotContains(
                'no-keyword-here',
                $titles,
                'Shared note must NOT appear when it does not match the search term '
                . '(AND predicate between visibility and search must be honoured)',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idB]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: non-matching own note is not returned
    // -----------------------------------------------------------------------

    /**
     * Negative case for the search predicate: userA's own note must NOT appear
     * if it does not match the search term.
     */
    public function testOwnNoteWithNonMatchingTermIsExcluded(): void {
        $idA = $this->insertNote('userA', 'irrelevant-title', 'irrelevant-content');

        try {
            $results = $this->execSearchAccessible('userA', [], 'xyzzy-not-in-note');

            $titles = array_column($results, 'title');

            $this->assertNotContains(
                'irrelevant-title',
                $titles,
                'userA own note must not appear when it does not match the search term',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idA]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: two users insert notes; each user only sees her own
    // -----------------------------------------------------------------------

    /**
     * Both userA and userB have notes matching the same search term.
     * userA sees only her note; userB sees only hers.
     *
     * This is the multi-user predicate-isolation test for the realistic case
     * where the search term is not unique across the whole table.
     */
    public function testEachUserSeesOnlyOwnMatchingNotes(): void {
        $idA = $this->insertNote('userA', 'common-search-tag note from userA', 'bodyA');
        $idB = $this->insertNote('userB', 'common-search-tag note from userB', 'bodyB');

        try {
            $resultsA = $this->execSearchAccessible('userA', [], 'common-search-tag');
            $resultsB = $this->execSearchAccessible('userB', [], 'common-search-tag');

            $userIdsInA = array_column($resultsA, 'user_id');
            $userIdsInB = array_column($resultsB, 'user_id');

            $this->assertNotContains(
                'userB',
                $userIdsInA,
                'userA search results must not contain userB notes',
            );
            $this->assertNotContains(
                'userA',
                $userIdsInB,
                'userB search results must not contain userA notes',
            );
            $this->assertContains('userA', $userIdsInA, 'userA must find her own note');
            $this->assertContains('userB', $userIdsInB, 'userB must find her own note');
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id IN (?, ?)');
            $stmt->execute([$idA, $idB]);
        }
    }

    // -----------------------------------------------------------------------
    // Test: special LIKE metacharacters in term are escaped (injection guard)
    // -----------------------------------------------------------------------

    /**
     * Document the ACTUAL engine-dependent behaviour of the production search
     * path for a '%' term (escapeLikeParameter() backslash-escape -> pattern
     * '%\%%' + iLike() with no ESCAPE clause), measured against a live engine.
     *
     * Security-relevant invariant, true on EVERY engine: a literal '%' search
     * does NOT match all rows. (And even if it did, the search predicate is ANDed
     * with the owner/shared visibility predicate, so it could only over-match the
     * caller's own/shared rows — never a cross-user leak.)
     *
     * The reason differs by engine, and that difference is the point of this test:
     * - MySQL/PostgreSQL honour backslash as the default LIKE escape, so '%\%%'
     *   matches exactly the literal-'%' rows — proper neutralisation. A decoy
     *   without a literal '%' does not match; a row containing '%' would.
     * - SQLite has no default LIKE escape and iLike() emits no ESCAPE clause, so
     *   the backslash is a LITERAL character in the pattern. '%\%%' therefore
     *   matches only rows that actually contain a backslash — so the decoy is not
     *   matched (match-all is still prevented), but note that a row containing a
     *   literal '%' would ALSO not be found on SQLite. That over-restriction is the
     *   bounded correctness quirk documented in NoteMapper::buildLikePattern().
     *
     * Either way the decoy must be absent, which is what we assert on all engines.
     */
    public function testLikeMetacharacterDoesNotMatchAllRows(): void {
        // Decoy note that does NOT contain a literal '%' or backslash in either column.
        $idDecoy = $this->insertNote('userA', 'regular-note-no-percent', 'ordinary body text');

        try {
            // Search for the literal '%' character. On no supported engine may this
            // degrade to a match-all (which would return the decoy).
            $results = $this->execSearchAccessible('userA', [], '%');
            $titles  = array_column($results, 'title');

            $this->assertNotContains(
                'regular-note-no-percent',
                $titles,
                "A literal '%' search term must never match all rows on any engine "
                . '(would expose escapeLikeParameter/no-ESCAPE-clause neutralisation regressing to a flat wildcard).',
            );
        } finally {
            $stmt = $this->pdo->prepare('DELETE FROM ' . TP_TEST_NOTES_TABLE . ' WHERE id = ?');
            $stmt->execute([$idDecoy]);
        }
    }
}
