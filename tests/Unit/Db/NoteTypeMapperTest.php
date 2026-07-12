<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Behavioural tests for NoteTypeMapper against a real in-memory SQLite
 * database (see SqliteTestCase). These insert real rows and assert on the
 * rows the SHIPPED mapper methods actually return.
 *
 * The central security property under test is the read/write scope split:
 * findAll()/findById() (read scope) must include the caller's own rows PLUS
 * the shared global-default set (empty user_id, is_default = true), while
 * findOwnedById() (mutation scope) must see ONLY the caller's own rows —
 * never another user's rows and never the globals. A mock can only prove that
 * orX()/andX()/eq() were invoked a certain number of times; it cannot prove
 * the resulting predicate actually excludes another user's row. These tests
 * prove it by inserting a real other-user row and asserting it is absent.
 */
class NoteTypeMapperTest extends SqliteTestCase {

    public function testTableName(): void {
        $this->assertSame('touchpoint_note_types', $this->makeNoteTypeMapper()->getTableName());
    }

    public function testFindAllReturnsOwnTypesOrderedByName(): void {
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Zebra']);
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Alpha']);

        $types = $this->makeNoteTypeMapper()->findAll('user1');

        $this->assertCount(2, $types);
        $this->assertSame('Alpha', $types[0]->getName());
        $this->assertSame('Zebra', $types[1]->getName());
    }

    public function testFindAllExcludesOtherUsersTypes(): void {
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);
        $this->insertNoteType(['user_id' => 'other', 'name' => 'NotMine']);

        $types = $this->makeNoteTypeMapper()->findAll('user1');

        $names = array_map(fn ($t) => $t->getName(), $types);
        $this->assertContains('Mine', $names);
        $this->assertNotContains('NotMine', $names, 'Another user\'s type must not be visible');
    }

    public function testFindAllIncludesGlobalDefaults(): void {
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);
        $this->insertNoteType(['user_id' => '', 'name' => 'Global', 'is_default' => 1]);
        $this->insertNoteType(['user_id' => 'other', 'name' => 'NotMine']);

        $types = $this->makeNoteTypeMapper()->findAll('user1');

        $names = array_map(fn ($t) => $t->getName(), $types);
        sort($names);
        $this->assertSame(['Global', 'Mine'], $names, 'Read scope = own rows + shared global defaults');
    }

    /**
     * A row with an empty user_id but is_default = false is NOT a global
     * default (only the combination is the sentinel); it must not leak into
     * another user's read scope.
     */
    public function testFindAllExcludesEmptyUserIdRowsThatAreNotDefault(): void {
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);
        $this->insertNoteType(['user_id' => '', 'name' => 'OrphanedNonDefault', 'is_default' => 0]);

        $types = $this->makeNoteTypeMapper()->findAll('user1');

        $names = array_map(fn ($t) => $t->getName(), $types);
        $this->assertNotContains('OrphanedNonDefault', $names);
    }

    public function testFindByIdReturnsOwnType(): void {
        $id = $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);

        $type = $this->makeNoteTypeMapper()->findById($id, 'user1');

        $this->assertSame('Mine', $type->getName());
    }

    public function testFindByIdReturnsGlobalDefault(): void {
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Global', 'is_default' => 1]);

        $type = $this->makeNoteTypeMapper()->findById($id, 'user1');

        $this->assertSame('Global', $type->getName());
    }

    public function testFindByIdThrowsForOtherUsersType(): void {
        $id = $this->insertNoteType(['user_id' => 'userB', 'name' => 'NotYours']);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findById($id, 'userA');
    }

    public function testFindByIdThrowsForMissingType(): void {
        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findById(999999, 'user1');
    }

    /**
     * findOwnedById() is the mutation-scope lookup: it must see the caller's
     * own row and must NOT see the global-default set (globals are
     * read-shared but immutable), proving the security-relevant asymmetry
     * between findById() and findOwnedById().
     */
    public function testFindOwnedByIdReturnsOwnType(): void {
        $id = $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);

        $type = $this->makeNoteTypeMapper()->findOwnedById($id, 'user1');

        $this->assertSame('Mine', $type->getName());
    }

    public function testFindOwnedByIdExcludesGlobalDefaults(): void {
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Global', 'is_default' => 1]);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findOwnedById($id, 'user1');
    }

    public function testFindOwnedByIdExcludesOtherUsersType(): void {
        $id = $this->insertNoteType(['user_id' => 'userB', 'name' => 'NotYours']);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findOwnedById($id, 'userA');
    }

    public function testFindGlobalDefaultsReturnsOnlyTheSharedSentinelSet(): void {
        $this->insertNoteType(['user_id' => '', 'name' => 'Global1', 'is_default' => 1]);
        $this->insertNoteType(['user_id' => '', 'name' => 'Global2', 'is_default' => 1]);
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);
        $this->insertNoteType(['user_id' => '', 'name' => 'OrphanedNonDefault', 'is_default' => 0]);

        $types = $this->makeNoteTypeMapper()->findGlobalDefaults();

        $names = array_map(fn ($t) => $t->getName(), $types);
        sort($names);
        $this->assertSame(['Global1', 'Global2'], $names);
    }

    public function testFindGlobalDefaultsReturnsEmptyWhenNoneSeeded(): void {
        $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);

        $types = $this->makeNoteTypeMapper()->findGlobalDefaults();

        $this->assertSame([], $types);
    }

    /**
     * findGlobalById() is the admin mutation-scope lookup: it must find a
     * global default row by id, and must NOT match a regular user-owned row
     * even if the id happens to line up — admin mutation is scoped to the
     * shared sentinel set only, never a real user's own type.
     */
    public function testFindGlobalByIdReturnsGlobalDefault(): void {
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Global', 'is_default' => 1]);

        $type = $this->makeNoteTypeMapper()->findGlobalById($id);

        $this->assertSame('Global', $type->getName());
        $this->assertSame('', $type->getUserId());
        $this->assertTrue($type->getIsDefault());
    }

    public function testFindGlobalByIdExcludesUserOwnedType(): void {
        $id = $this->insertNoteType(['user_id' => 'user1', 'name' => 'Mine']);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findGlobalById($id);
    }

    public function testFindGlobalByIdExcludesEmptyUserIdRowThatIsNotDefault(): void {
        // Only user_id = '' AND is_default = true is the sentinel; an
        // orphaned empty-user_id/non-default row must not be admin-mutable.
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Orphaned', 'is_default' => 0]);

        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findGlobalById($id);
    }

    public function testFindGlobalByIdThrowsForMissingType(): void {
        $this->expectException(DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findGlobalById(999999);
    }

    // countGlobalUsage() was removed from NoteTypeMapper: it duplicated
    // NoteMapper::countByNoteType()'s SQL for the null-$userId (system-wide)
    // case. NoteTypeService::deleteGlobal()/countGlobalUsage() now call
    // NoteMapper::countByNoteType($id) directly instead — see
    // NoteMapperTest for coverage of that method's null-$userId behavior, and
    // NoteTypeServiceGlobalAdminSqliteTest for an end-to-end (real SQL, real
    // service) proof that the admin delete guard still sees every user's notes.
}
