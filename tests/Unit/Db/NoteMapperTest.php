<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\NoteMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Behavioural tests for NoteMapper against a real in-memory SQLite database
 * (see SqliteTestCase). These insert real rows and assert on the rows the
 * SHIPPED mapper methods actually return, rather than verifying which
 * query-builder methods were called with which arguments — a mock cannot
 * prove that a WHERE clause is correctly scoped, only that a method was
 * invoked.
 */
class NoteMapperTest extends SqliteTestCase {

    public function testTableName(): void {
        $this->assertSame('touchpoint_notes', $this->makeNoteMapper()->getTableName());
    }

    public function testFindAllReturnsOnlyOwnNotesNewestFirstByDefault(): void {
        $this->insertNote(['user_id' => 'user1', 'title' => 'first', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'user1', 'title' => 'second', 'created_at' => '2026-01-02 00:00:00']);
        $this->insertNote(['user_id' => 'other', 'title' => 'not-mine', 'created_at' => '2026-01-03 00:00:00']);

        $notes = $this->makeNoteMapper()->findAll('user1');

        $this->assertCount(2, $notes, 'Only user1 notes must be returned');
        // Default sort is newest-first (created_at DESC).
        $this->assertSame('second', $notes[0]->getTitle());
        $this->assertSame('first', $notes[1]->getTitle());
    }

    public function testFindAllOldestSortAscends(): void {
        $this->insertNote(['user_id' => 'user1', 'title' => 'first', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'user1', 'title' => 'second', 'created_at' => '2026-01-02 00:00:00']);

        $notes = $this->makeNoteMapper()->findAll('user1', null, null, NoteMapper::SORT_OLDEST);

        $this->assertSame('first', $notes[0]->getTitle());
        $this->assertSame('second', $notes[1]->getTitle());
    }

    /**
     * When multiple notes share the same created_at, the id tiebreaker keeps
     * LIMIT/OFFSET paging deterministic instead of reordering ties per query.
     */
    public function testFindAllUsesIdAsStableTiebreaker(): void {
        $sameTimestamp = '2026-01-01 00:00:00';
        $id1 = $this->insertNote(['user_id' => 'user1', 'title' => 'a', 'created_at' => $sameTimestamp]);
        $id2 = $this->insertNote(['user_id' => 'user1', 'title' => 'b', 'created_at' => $sameTimestamp]);

        $notes = $this->makeNoteMapper()->findAll('user1');

        // Newest-first tiebreaker is id DESC, so the higher id comes first.
        $this->assertSame($id2, $notes[0]->getId());
        $this->assertSame($id1, $notes[1]->getId());
    }

    public function testFindAllWithLimitAndOffset(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertNote(['user_id' => 'user1', 'title' => "note-$i", 'created_at' => "2026-01-0{$i} 00:00:00"]);
        }

        // Newest-first: note-5, note-4, note-3, note-2, note-1.
        $page = $this->makeNoteMapper()->findAll('user1', 2, 1);

        $this->assertCount(2, $page);
        $this->assertSame('note-4', $page[0]->getTitle());
        $this->assertSame('note-3', $page[1]->getTitle());
    }

    public function testFindAllWithoutLimitAndOffsetReturnsEverything(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->insertNote(['user_id' => 'user1', 'title' => "note-$i"]);
        }

        $notes = $this->makeNoteMapper()->findAll('user1');

        $this->assertCount(3, $notes);
    }

    public function testFindAllPublicReturnsNotesFromEveryUser(): void {
        $this->insertNote(['user_id' => 'user1', 'title' => 'mine', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'other', 'title' => 'theirs', 'created_at' => '2026-01-02 00:00:00']);

        $notes = $this->makeNoteMapper()->findAllPublic();

        $titles = array_map(fn ($n) => $n->getTitle(), $notes);
        $this->assertContains('mine', $titles);
        $this->assertContains('theirs', $titles);
        $this->assertCount(2, $notes);
    }

    public function testFindAllPublicOldestSortAscends(): void {
        $this->insertNote(['user_id' => 'user1', 'title' => 'first', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'user1', 'title' => 'second', 'created_at' => '2026-01-02 00:00:00']);

        $notes = $this->makeNoteMapper()->findAllPublic(null, null, NoteMapper::SORT_OLDEST);

        $this->assertSame('first', $notes[0]->getTitle());
        $this->assertSame('second', $notes[1]->getTitle());
    }

    public function testFindAllPublicWithLimitAndOffset(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertNote(['user_id' => 'user1', 'title' => "note-$i", 'created_at' => "2026-01-0{$i} 00:00:00"]);
        }

        $page = $this->makeNoteMapper()->findAllPublic(2, 1);

        $this->assertCount(2, $page);
        $this->assertSame('note-4', $page[0]->getTitle());
        $this->assertSame('note-3', $page[1]->getTitle());
    }

    public function testFindByIdReturnsOwnNote(): void {
        $id = $this->insertNote(['user_id' => 'user1', 'title' => 'mine']);

        $note = $this->makeNoteMapper()->findById($id, 'user1');

        $this->assertSame('mine', $note->getTitle());
        $this->assertSame($id, $note->getId());
    }

    public function testFindByIdThrowsForMissingNote(): void {
        $this->expectException(DoesNotExistException::class);
        $this->makeNoteMapper()->findById(999999, 'user1');
    }

    /**
     * findById() scopes by owner: another user's note must not be returned
     * even when the id is correct. A mock-based test could only assert that
     * eq('user_id', ...) was called — it could not prove the predicate is
     * ANDed (not ORed) with the id predicate. This test proves the real
     * behaviour.
     */
    public function testFindByIdDoesNotLeakOtherUsersNote(): void {
        $id = $this->insertNote(['user_id' => 'userB', 'title' => 'not-yours']);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteMapper()->findById($id, 'userA');
    }

    public function testFindByIdPublicIgnoresOwner(): void {
        $id = $this->insertNote(['user_id' => 'userB', 'title' => 'anyones']);

        $note = $this->makeNoteMapper()->findByIdPublic($id);

        $this->assertSame('anyones', $note->getTitle());
    }

    public function testFindByIdsEmptyReturnsEarly(): void {
        $this->assertSame([], $this->makeNoteMapper()->findByIds([], null));
    }

    public function testFindByIdsReturnsOnlyRequestedIdsForOwner(): void {
        $id1 = $this->insertNote(['user_id' => 'user1', 'title' => 'a']);
        $id2 = $this->insertNote(['user_id' => 'user1', 'title' => 'b']);
        $this->insertNote(['user_id' => 'user1', 'title' => 'c']);

        $notes = $this->makeNoteMapper()->findByIds([$id1, $id2], 'user1');

        $this->assertCount(2, $notes);
        $titles = array_map(fn ($n) => $n->getTitle(), $notes);
        sort($titles);
        $this->assertSame(['a', 'b'], $titles);
    }

    public function testFindByIdsScopesToOwnerWhenUserIdGiven(): void {
        $idA = $this->insertNote(['user_id' => 'userA', 'title' => 'a']);
        $idB = $this->insertNote(['user_id' => 'userB', 'title' => 'b']);

        // userA asks for both ids, but only her own must come back.
        $notes = $this->makeNoteMapper()->findByIds([$idA, $idB], 'userA');

        $this->assertCount(1, $notes);
        $this->assertSame('a', $notes[0]->getTitle());
    }

    public function testFindByIdsWithNullUserIdSkipsOwnerScoping(): void {
        $idA = $this->insertNote(['user_id' => 'userA', 'title' => 'a']);
        $idB = $this->insertNote(['user_id' => 'userB', 'title' => 'b']);

        $notes = $this->makeNoteMapper()->findByIds([$idA, $idB], null);

        $this->assertCount(2, $notes);
    }

    /**
     * findByIds() must batch a large id list into chunks of at most 900 so the
     * IN(...) clause never overflows a DB backend's per-query list limit.
     * Rather than counting getQueryBuilder() calls (a mock-only observable),
     * assert on the actually-correct behaviour: every real id is found even
     * when padded with enough decoy ids to force multiple chunks.
     */
    public function testFindByIdsChunksLargeIdListAndStillFindsAllMatches(): void {
        $realIds = [];
        for ($i = 0; $i < 5; $i++) {
            $realIds[] = $this->insertNote(['user_id' => 'user1', 'title' => "note-$i"]);
        }
        // Pad past the 900-per-chunk boundary (2000 ids => 3 chunks of 900/900/200).
        $paddedIds = array_merge($realIds, range(1000000, 1001999));

        $notes = $this->makeNoteMapper()->findByIds($paddedIds, 'user1');

        $this->assertCount(5, $notes, 'All real ids must be found across chunk boundaries');
        $foundIds = array_map(fn ($n) => $n->getId(), $notes);
        sort($foundIds);
        sort($realIds);
        $this->assertSame($realIds, $foundIds);
    }

    public function testFindSortKeysByIdsEmptyReturnsEarly(): void {
        $this->assertSame([], $this->makeNoteMapper()->findSortKeysByIds([], null));
    }

    /**
     * findSortKeysByIds() must surface the is_pinned flag (cast to bool) and
     * the created_at sort key so contact-scoped callers can apply their
     * pinned-first, created_at ordering on the id window without loading full
     * rows. updated_at must NOT be part of the returned shape.
     */
    public function testFindSortKeysByIdsReturnsCreatedAtAndPinnedFlag(): void {
        $id1 = $this->insertNote(['user_id' => 'user1', 'created_at' => '2026-05-01 00:00:00', 'is_pinned' => 1]);
        $id2 = $this->insertNote(['user_id' => 'user1', 'created_at' => '2026-05-02 00:00:00', 'is_pinned' => 0]);

        $map = $this->makeNoteMapper()->findSortKeysByIds([$id1, $id2], 'user1');

        $this->assertTrue($map[$id1]['is_pinned']);
        $this->assertFalse($map[$id2]['is_pinned']);
        $this->assertSame('2026-05-01 00:00:00', $map[$id1]['created_at']);
        $this->assertSame('2026-05-02 00:00:00', $map[$id2]['created_at']);
        $this->assertArrayNotHasKey('updated_at', $map[$id1]);
    }

    public function testFindSortKeysByIdsScopesToOwner(): void {
        $idA = $this->insertNote(['user_id' => 'userA']);
        $idB = $this->insertNote(['user_id' => 'userB']);

        $map = $this->makeNoteMapper()->findSortKeysByIds([$idA, $idB], 'userA');

        $this->assertArrayHasKey($idA, $map);
        $this->assertArrayNotHasKey($idB, $map, 'userB note must not appear in userA-scoped lookup');
    }

    public function testFindSortKeysByIdsChunksLargeIdList(): void {
        $realIds = [];
        for ($i = 0; $i < 3; $i++) {
            $realIds[] = $this->insertNote(['user_id' => 'user1']);
        }
        $paddedIds = array_merge($realIds, range(2000000, 2001999));

        $map = $this->makeNoteMapper()->findSortKeysByIds($paddedIds, 'user1');

        $this->assertCount(3, $map, 'All real ids must be found across chunk boundaries');
        foreach ($realIds as $id) {
            $this->assertArrayHasKey($id, $map);
        }
    }

    /**
     * findAccessiblePage() must return notes the user owns UNIONED with the
     * explicitly-shared id set, ordered by created_at (newest-first by
     * default) with the id tiebreaker, and apply LIMIT/OFFSET in SQL.
     */
    public function testFindAccessiblePageReturnsOwnedAndSharedNotes(): void {
        $ownId = $this->insertNote(['user_id' => 'userA', 'title' => 'owned', 'created_at' => '2026-01-01 00:00:00']);
        $sharedId = $this->insertNote(['user_id' => 'userB', 'title' => 'shared', 'created_at' => '2026-01-02 00:00:00']);
        $this->insertNote(['user_id' => 'userC', 'title' => 'not-accessible', 'created_at' => '2026-01-03 00:00:00']);

        $notes = $this->makeNoteMapper()->findAccessiblePage('userA', [$sharedId]);

        $titles = array_map(fn ($n) => $n->getTitle(), $notes);
        $this->assertContains('owned', $titles);
        $this->assertContains('shared', $titles);
        $this->assertNotContains('not-accessible', $titles);
        $this->assertCount(2, $notes);
        // Newest-first: shared (Jan 2) before owned (Jan 1).
        $this->assertSame('shared', $notes[0]->getTitle());
        $this->assertSame('owned', $notes[1]->getTitle());
    }

    public function testFindAccessiblePageOldestSortAscends(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'first', 'created_at' => '2026-01-01 00:00:00']);
        $this->insertNote(['user_id' => 'userA', 'title' => 'second', 'created_at' => '2026-01-02 00:00:00']);

        $notes = $this->makeNoteMapper()->findAccessiblePage('userA', [], null, null, NoteMapper::SORT_OLDEST);

        $this->assertSame('first', $notes[0]->getTitle());
        $this->assertSame('second', $notes[1]->getTitle());
    }

    public function testFindAccessiblePageAppliesLimitAndOffset(): void {
        for ($i = 1; $i <= 5; $i++) {
            $this->insertNote(['user_id' => 'userA', 'title' => "note-$i", 'created_at' => "2026-01-0{$i} 00:00:00"]);
        }

        $page = $this->makeNoteMapper()->findAccessiblePage('userA', [], 25, 3);

        $this->assertCount(2, $page, 'Offset 3 of 5 newest-first rows leaves 2');
        $this->assertSame('note-2', $page[0]->getTitle());
        $this->assertSame('note-1', $page[1]->getTitle());
    }

    public function testFindAccessiblePageWithNoSharesReturnsOnlyOwnNotes(): void {
        $this->insertNote(['user_id' => 'userA', 'title' => 'mine']);
        $this->insertNote(['user_id' => 'userB', 'title' => 'not-mine']);

        $notes = $this->makeNoteMapper()->findAccessiblePage('userA', [], null, null);

        $this->assertCount(1, $notes);
        $this->assertSame('mine', $notes[0]->getTitle());
    }

    /**
     * findAccessiblePage() must split a shared-id set larger than the per-IN
     * cap (900) into chunked OR(IN ...) groups so the single ordered+windowed
     * query is preserved — proven here by having every one of >900 shared ids
     * actually resolve to a returned row.
     */
    public function testFindAccessiblePageChunksLargeSharedIdSet(): void {
        $sharedIds = [];
        for ($i = 0; $i < 5; $i++) {
            $sharedIds[] = $this->insertNote(['user_id' => 'userB', 'title' => "shared-$i"]);
        }
        // Pad past the 900-per-chunk boundary with ids that do not exist.
        $paddedSharedIds = array_merge($sharedIds, range(3000000, 3001999));

        $notes = $this->makeNoteMapper()->findAccessiblePage('userA', $paddedSharedIds);

        $this->assertCount(5, $notes, 'All real shared notes must be found across chunk boundaries');
    }

    public function testCountByNoteTypeCountsOnlyMatchingRows(): void {
        $this->insertNote(['user_id' => 'user1', 'note_type_id' => 1]);
        $this->insertNote(['user_id' => 'user1', 'note_type_id' => 1]);
        $this->insertNote(['user_id' => 'user1', 'note_type_id' => 2]);

        $this->assertSame(2, $this->makeNoteMapper()->countByNoteType(1));
        $this->assertSame(1, $this->makeNoteMapper()->countByNoteType(2));
        $this->assertSame(0, $this->makeNoteMapper()->countByNoteType(3));
    }

    public function testCountByNoteTypeScopesToOwnerWhenGiven(): void {
        $this->insertNote(['user_id' => 'userA', 'note_type_id' => 1]);
        $this->insertNote(['user_id' => 'userB', 'note_type_id' => 1]);

        $this->assertSame(1, $this->makeNoteMapper()->countByNoteType(1, 'userA'));
        $this->assertSame(2, $this->makeNoteMapper()->countByNoteType(1));
    }

    public function testFindIdsByContactReturnsMatchingIdsAcrossOwners(): void {
        $id1 = $this->insertNote(['user_id' => 'userA', 'contact_uid' => 'contact-1']);
        $this->insertNote(['user_id' => 'userA', 'contact_uid' => 'contact-2']);
        $id3 = $this->insertNote(['user_id' => 'userB', 'contact_uid' => 'contact-1']);

        $ids = $this->makeNoteMapper()->findIdsByContact('contact-1');

        sort($ids);
        $expected = [$id1, $id3];
        sort($expected);
        $this->assertSame($expected, $ids, 'Lookup is unscoped by owner so shared/other-owner notes are still found');
    }
}
