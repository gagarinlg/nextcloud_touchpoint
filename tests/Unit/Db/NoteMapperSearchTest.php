<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\NoteMapper;

/**
 * Behavioural tests for NoteMapper::searchAccessiblePage() against a real
 * in-memory SQLite database (see SqliteTestCase).
 *
 * These tests insert real rows and assert on the notes the SHIPPED
 * searchAccessiblePage() method actually returns. Unlike the previous
 * mock-based version, they prove the security-critical property directly:
 * the visibility predicate (owned OR shared) and the search predicate
 * (title/content match) are ANDed together, so a note that is shared but does
 * not match the term is excluded, and a note that matches the term but is
 * neither owned nor shared is never returned. A mock can only prove that
 * where()/andWhere() were called; it cannot prove the resulting SQL is
 * correctly parenthesised. The cross-engine equivalent of this suite lives in
 * tests/Integration/Db/NoteMapperSearchTest.php.
 *
 * SQLite LIKE is case-insensitive for ASCII only; fixture terms here are
 * ASCII-only so results are deterministic on SQLite.
 */
class NoteMapperSearchTest extends SqliteTestCase {

    public function testSearchMatchesTitle(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'quarterly-report-note', 'content' => 'body']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'quarterly-report', null, null);

        $this->assertCount(1, $notes);
        $this->assertSame('quarterly-report-note', $notes[0]->getTitle());
    }

    public function testSearchMatchesContent(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'plain-title', 'content' => 'findable-content-keyword']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'findable-content', null, null);

        $this->assertCount(1, $notes);
        $this->assertSame('plain-title', $notes[0]->getTitle());
    }

    /**
     * The central security claim: userA searching for a term that only
     * appears in userB's (non-shared) note must get zero results. A flat-OR
     * mis-parenthesisation of visibility/search would leak this note.
     */
    public function testUserBNoteNotReturnedWhenSearchingAsUserA(): void {
        $this->insertNote(['user_id' => 'userB', 'title' => 'userB-secret-note', 'content' => 'private content of userB']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'userB-secret', null, null);

        $this->assertCount(0, $notes, 'userA must not see userB note via search');
    }

    /**
     * AND-semantics proof: a note explicitly shared with userA must NOT
     * appear when it does not match the search term. A flat-OR
     * (visibility OR search) would return every shared note regardless of
     * term.
     */
    public function testSharedNoteWithNonMatchingTermIsExcluded(): void {
        $sharedId = $this->insertNote(['user_id' => 'userB', 'title' => 'no-keyword-here', 'content' => 'also-no-keyword']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [$sharedId], 'findme-term', null, null);

        $this->assertCount(0, $notes, 'Shared-but-non-matching note must be excluded');
    }

    public function testSharedNoteWithMatchingTermIsReturned(): void {
        $sharedId = $this->insertNote(['user_id' => 'userB', 'title' => 'shared-with-userA', 'content' => 'shared-keyword-value']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [$sharedId], 'shared-keyword', null, null);

        $this->assertCount(1, $notes);
        $this->assertSame('shared-with-userA', $notes[0]->getTitle());
    }

    public function testOwnNoteWithNonMatchingTermIsExcluded(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'irrelevant-title', 'content' => 'irrelevant-content']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'xyzzy-not-in-note', null, null);

        $this->assertCount(0, $notes);
    }

    public function testEachUserSeesOnlyOwnMatchingNotes(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'common-search-tag from userA', 'content' => 'bodyA']);
        $this->insertNote(['user_id' => 'userB', 'title' => 'common-search-tag from userB', 'content' => 'bodyB']);

        $notesA = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'common-search-tag', null, null);
        $notesB = $this->makeNoteMapper()->searchAccessiblePage('userB', [], 'common-search-tag', null, null);

        $this->assertCount(1, $notesA);
        $this->assertSame('userA', $notesA[0]->getUserId());
        $this->assertCount(1, $notesB);
        $this->assertSame('userB', $notesB[0]->getUserId());
    }

    /**
     * A literal '%' term must never match all rows (production
     * escapeLikeParameter()/buildLikePattern() path). The security-relevant
     * invariant holds on every engine: even if metacharacter neutralisation
     * behaves differently across engines, the search predicate is ANDed with
     * the visibility predicate, so it can only ever over/under-match the
     * caller's own/shared rows — never leak across users.
     */
    public function testLiteralPercentTermDoesNotMatchAllRows(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'regular-note-no-percent', 'content' => 'ordinary body text']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], '%', null, null);

        $this->assertCount(0, $notes, "A literal '%' term must never match all rows");
    }

    public function testEmptyTermDoesNotThrowAndScopesToOwner(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'anything']);
        $this->insertNote(['user_id' => 'userB', 'title' => 'something-else']);

        // NoteService intercepts blank terms before the mapper is called, but
        // the mapper itself must handle an empty string gracefully. An empty
        // pattern ('%%') matches every accessible row, never a cross-user one.
        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], '', null, null);

        $this->assertCount(1, $notes);
        $this->assertSame('anything', $notes[0]->getTitle());
    }

    public function testLimitAndOffsetAreApplied(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertNote([
                'user_id' => 'userA',
                'title' => "match-me-$i",
                'created_at' => "2026-01-0{$i} 00:00:00",
            ]);
        }

        $page = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'match-me', 2, 1);

        $this->assertCount(2, $page);
        // Newest-first default: match-me-5, match-me-4, match-me-3, match-me-2, match-me-1.
        // Offset 1 => match-me-4, match-me-3.
        $this->assertSame('match-me-4', $page[0]->getTitle());
        $this->assertSame('match-me-3', $page[1]->getTitle());
    }

    public function testOldestSortAscends(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'match-first', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'userA', 'title' => 'match-second', 'created_at' => '2026-01-02 00:00:00']);

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', [], 'match', null, null, NoteMapper::SORT_OLDEST);

        $this->assertSame('match-first', $notes[0]->getTitle());
        $this->assertSame('match-second', $notes[1]->getTitle());
    }

    /**
     * A shared-id set larger than the per-IN cap (900) must be chunked into
     * OR(IN ...) groups while still ANDing with the search predicate. Proven
     * here by having real shared+matching notes surface despite heavy padding
     * with nonexistent ids across chunk boundaries.
     */
    public function testChunksLargeSharedIdSetAndStillAppliesSearchPredicate(): void {
        $sharedIds = [];
        for ($i = 0; $i < 3; $i++) {
            $sharedIds[] = $this->insertNote(['user_id' => 'userB', 'title' => "chunked-match-$i", 'content' => 'body']);
        }
        // A shared note that does NOT match the term must still be excluded
        // even with the chunked IN(...) visibility predicate.
        $nonMatching = $this->insertNote(['user_id' => 'userB', 'title' => 'no-match-here', 'content' => 'body']);
        $paddedSharedIds = array_merge($sharedIds, [$nonMatching], range(4000000, 4001999));

        $notes = $this->makeNoteMapper()->searchAccessiblePage('userA', $paddedSharedIds, 'chunked-match', null, null);

        $this->assertCount(3, $notes, 'All matching shared notes must be found across chunk boundaries');
        $titles = array_map(fn ($n) => $n->getTitle(), $notes);
        $this->assertNotContains('no-match-here', $titles);
    }
}
