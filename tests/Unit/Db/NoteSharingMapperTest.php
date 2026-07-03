<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

/**
 * Behavioural tests for NoteSharingMapper against a real in-memory SQLite
 * database (see SqliteTestCase). These insert real rows and assert on the
 * ids/entities the SHIPPED mapper methods actually return.
 *
 * Covers the read-vs-write share distinction (C1): findWritableNoteIds() must
 * narrow the query with the can_edit predicate, while findAccessibleNoteIds()
 * must not. A mock can only prove andWhere() was (or wasn't) called; these
 * tests insert a real can_edit=false share and prove it is excluded from
 * findWritableNoteIds() but included in findAccessibleNoteIds().
 */
class NoteSharingMapperTest extends SqliteTestCase {

    public function testTableName(): void {
        $this->assertSame('touchpoint_note_sharing', $this->makeNoteSharingMapper()->getTableName());
    }

    public function testFindAccessibleNoteIdsReturnsUserShares(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'user', 'user1', false);

        $ids = $this->makeNoteSharingMapper()->findAccessibleNoteIds('user1', []);

        $this->assertSame([$noteId], $ids);
    }

    public function testFindAccessibleNoteIdsIncludesGroupShares(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'group', 'staff', false);

        $ids = $this->makeNoteSharingMapper()->findAccessibleNoteIds('user1', ['staff', 'sales']);

        $this->assertSame([$noteId], $ids);
    }

    public function testFindAccessibleNoteIdsExcludesUnrelatedShares(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'user', 'someoneElse', false);

        $ids = $this->makeNoteSharingMapper()->findAccessibleNoteIds('user1', []);

        $this->assertSame([], $ids);
    }

    /**
     * findAccessibleNoteIds() (read access) must NOT filter on can_edit: a
     * read-only share (can_edit = false) must still be returned.
     */
    public function testFindAccessibleNoteIdsDoesNotFilterOnCanEdit(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'user', 'user1', false);

        $ids = $this->makeNoteSharingMapper()->findAccessibleNoteIds('user1', []);

        $this->assertSame([$noteId], $ids, 'A read-only (can_edit=false) share must still be accessible');
    }

    /**
     * findWritableNoteIds() must narrow to can_edit = true: a read-only share
     * must be excluded, proving the write-scope predicate is real, not just a
     * method call the mapper happens to make.
     */
    public function testFindWritableNoteIdsExcludesReadOnlyShare(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'user', 'user1', false);

        $ids = $this->makeNoteSharingMapper()->findWritableNoteIds('user1', []);

        $this->assertSame([], $ids, 'A can_edit=false share must not be writable');
    }

    public function testFindWritableNoteIdsIncludesEditableShare(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'user', 'user1', true);

        $ids = $this->makeNoteSharingMapper()->findWritableNoteIds('user1', []);

        $this->assertSame([$noteId], $ids);
    }

    public function testFindWritableNoteIdsIncludesEditableGroupShare(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'group', 'staff', true);

        $ids = $this->makeNoteSharingMapper()->findWritableNoteIds('user1', ['staff', 'sales']);

        $this->assertSame([$noteId], $ids);
    }

    public function testFindWritableNoteIdsExcludesNonEditableGroupShare(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'group', 'staff', false);

        $ids = $this->makeNoteSharingMapper()->findWritableNoteIds('user1', ['staff']);

        $this->assertSame([], $ids);
    }

    public function testFindWritableNoteIdsWithNoGroupsAndNoMatches(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId, 'group', 'staff', true);

        // user1 does not belong to 'staff', and no groups are passed.
        $ids = $this->makeNoteSharingMapper()->findWritableNoteIds('user1', []);

        $this->assertSame([], $ids);
    }

    public function testFindAccessibleNoteIdsReturnsDistinctIds(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        // Shared both directly and via a group the user belongs to.
        $this->insertSharing($noteId, 'user', 'user1', false);
        $this->insertSharing($noteId, 'group', 'staff', false);

        $ids = $this->makeNoteSharingMapper()->findAccessibleNoteIds('user1', ['staff']);

        $this->assertSame([$noteId], $ids, 'selectDistinct() must dedupe a note shared both directly and via group');
    }

    /**
     * syncSharing() must tolerate a unique-constraint violation on insert (the
     * touchpoint_note_sharing_unique index on (note_id, type, id)) the same
     * way NoteService::create()/addFile() do, so a duplicate target racing
     * past the service-side dedupe never surfaces as a 500. Driven against a
     * REAL SQLite UNIQUE constraint rather than a mocked exception.
     */
    public function testSyncSharingTargetsAreAllReadableAfterSync(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);

        $this->makeNoteSharingMapper()->syncSharing($noteId, [
            ['type' => 'user', 'id' => 'bob', 'canEdit' => true],
            ['type' => 'group', 'id' => 'staff'],
        ]);

        $this->assertSame(2, $this->countSharingRows($noteId));
        $this->assertSame([$noteId], $this->makeNoteSharingMapper()->findWritableNoteIds('bob', []));
        $this->assertSame([$noteId], $this->makeNoteSharingMapper()->findAccessibleNoteIds('someoneElse', ['staff']));
    }

    public function testSyncSharingReplacesPreviousTargets(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $mapper = $this->makeNoteSharingMapper();

        $mapper->syncSharing($noteId, [
            ['type' => 'user', 'id' => 'bob', 'canEdit' => true],
        ]);
        $this->assertSame(1, $this->countSharingRows($noteId));

        // Replacing with a different target set must delete the old rows.
        $mapper->syncSharing($noteId, [
            ['type' => 'user', 'id' => 'alice', 'canEdit' => false],
        ]);

        $this->assertSame(1, $this->countSharingRows($noteId));
        $this->assertSame([], $mapper->findAccessibleNoteIds('bob', []), 'bob\'s old share must be gone');
        $this->assertSame([$noteId], $mapper->findAccessibleNoteIds('alice', []));
    }

    public function testSyncSharingWithEmptyTargetsClearsAllSharing(): void {
        $noteId = $this->insertNote(['user_id' => 'owner']);
        $mapper = $this->makeNoteSharingMapper();
        $mapper->syncSharing($noteId, [['type' => 'user', 'id' => 'bob']]);

        $mapper->syncSharing($noteId, []);

        $this->assertSame(0, $this->countSharingRows($noteId));
    }

    public function testFindByNoteIdReturnsEntriesForThatNoteOnly(): void {
        $noteId1 = $this->insertNote(['user_id' => 'owner']);
        $noteId2 = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId1, 'user', 'bob', true);
        $this->insertSharing($noteId2, 'user', 'alice', false);

        $entries = $this->makeNoteSharingMapper()->findByNoteId($noteId1);

        $this->assertCount(1, $entries);
        $this->assertSame('bob', $entries[0]->getSharedWithId());
    }

    public function testFindByNoteIdsEmptyReturnsEarly(): void {
        $this->assertSame([], $this->makeNoteSharingMapper()->findByNoteIds([]));
    }

    public function testFindByNoteIdsGroupsEntriesByNoteId(): void {
        $noteId1 = $this->insertNote(['user_id' => 'owner']);
        $noteId2 = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId1, 'user', 'bob', true);
        $this->insertSharing($noteId2, 'user', 'alice', false);
        $this->insertSharing($noteId2, 'group', 'staff', false);

        $map = $this->makeNoteSharingMapper()->findByNoteIds([$noteId1, $noteId2]);

        $this->assertCount(1, $map[$noteId1]);
        $this->assertCount(2, $map[$noteId2]);
        $this->assertSame('bob', $map[$noteId1][0]->getSharedWithId());
    }

    /**
     * findByNoteIds() must batch a large note-id list into chunks of at most
     * 900 (matching NoteMapper) so the IN(...) clause never overflows a
     * strict DB backend's per-query list limit. Proven here by having every
     * real share entry resolve despite padding with nonexistent ids across
     * chunk boundaries.
     */
    public function testFindByNoteIdsChunksLargeIdListAndStillFindsAllMatches(): void {
        $realNoteIds = [];
        for ($i = 0; $i < 3; $i++) {
            $noteId = $this->insertNote(['user_id' => 'owner']);
            $this->insertSharing($noteId, 'user', "user-$i", false);
            $realNoteIds[] = $noteId;
        }
        $paddedIds = array_merge($realNoteIds, range(5000000, 5001999));

        $map = $this->makeNoteSharingMapper()->findByNoteIds($paddedIds);

        $this->assertCount(3, $map, 'All real note ids must be found across chunk boundaries');
        foreach ($realNoteIds as $noteId) {
            $this->assertArrayHasKey($noteId, $map);
        }
    }

    public function testDeleteByNoteIdRemovesOnlyThatNotesEntries(): void {
        $noteId1 = $this->insertNote(['user_id' => 'owner']);
        $noteId2 = $this->insertNote(['user_id' => 'owner']);
        $this->insertSharing($noteId1, 'user', 'bob', false);
        $this->insertSharing($noteId2, 'user', 'alice', false);

        $this->makeNoteSharingMapper()->deleteByNoteId($noteId1);

        $this->assertSame(0, $this->countSharingRows($noteId1));
        $this->assertSame(1, $this->countSharingRows($noteId2));
    }
}
