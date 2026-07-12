<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Service;

use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteTypeInUseException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\NoteValidationException;
use OCA\Touchpoint\Tests\Unit\Db\SqliteNoteTypeMapper;
use OCA\Touchpoint\Tests\Unit\Db\SqliteTestCase;
use OCP\AppFramework\Db\Entity;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * NoteTypeMapper that additionally performs real INSERT/UPDATE/DELETE via
 * PDO (SqliteTestCase's own SqliteNoteTypeMapper only overrides the SELECT
 * paths findEntity()/findEntities(); the base QBMapper stub's insert()/
 * update()/delete() are pure no-ops that never touch the database — see
 * tests/stubs.php). Mirrors the SqliteNoteSharingMapper pattern from
 * SqliteTestCase.php.
 */
class SqliteNoteTypeMapperWithWrites extends SqliteNoteTypeMapper {

    public function __construct(private PDO $pdo, $db) {
        parent::__construct($db);
    }

    public function insert(Entity $entity): Entity {
        /** @var NoteType $entity */
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . $this->getTableName() . '
                (name, icon, color, user_id, is_default)
             VALUES (:name, :icon, :color, :user_id, :is_default)'
        );
        $stmt->execute([
            ':name'       => $entity->getName(),
            ':icon'       => $entity->getIcon(),
            ':color'      => $entity->getColor(),
            ':user_id'    => $entity->getUserId(),
            ':is_default' => $entity->getIsDefault() ? 1 : 0,
        ]);
        $entity->setId((int) $this->pdo->lastInsertId());
        return $entity;
    }

    public function update(Entity $entity): Entity {
        /** @var NoteType $entity */
        $stmt = $this->pdo->prepare(
            'UPDATE ' . $this->getTableName() . '
                SET name = :name, icon = :icon, color = :color
             WHERE id = :id'
        );
        $stmt->execute([
            ':name'  => $entity->getName(),
            ':icon'  => $entity->getIcon(),
            ':color' => $entity->getColor(),
            ':id'    => $entity->getId(),
        ]);
        return $entity;
    }

    public function delete(Entity $entity): Entity {
        /** @var NoteType $entity */
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->getTableName() . ' WHERE id = :id');
        $stmt->execute([':id' => $entity->getId()]);
        return $entity;
    }
}

/**
 * Independent black-box verification of the admin global-note-type
 * acceptance criteria, run against the REAL NoteTypeMapper SELECT/UPDATE/
 * INSERT/DELETE SQL and a real SQLite database (via SqliteTestCase), with
 * the REAL NoteTypeService wired on top — nothing in this file mocks
 * NoteTypeMapper.
 *
 * Rationale: Fritz's NoteTypeServiceTest mocks NoteTypeMapper for
 * createGlobal/updateGlobal/deleteGlobal, which proves the service calls the
 * mapper correctly but cannot prove the mapper's SQL predicate, when actually
 * executed, produces the security property the AC requires: that admin
 * mutation endpoints can only ever reach the shared global-default row set
 * (user_id = '', is_default = true) and can NEVER reach, rename, or delete a
 * regular user's personal note type — even by guessing/iterating IDs. This
 * is exactly the kind of cross-layer wiring bug a mock cannot catch (e.g. if
 * findGlobalById() dropped its is_default predicate, every mocked test above
 * would still pass while the real endpoint became an IDOR).
 */
class NoteTypeServiceGlobalAdminSqliteTest extends SqliteTestCase {

    private function makeWritableNoteTypeMapper(): SqliteNoteTypeMapperWithWrites {
        return new SqliteNoteTypeMapperWithWrites($this->pdo, $this->db);
    }

    private function makeService(): NoteTypeService {
        return new NoteTypeService(
            $this->makeWritableNoteTypeMapper(),
            $this->makeNoteMapper(),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testCreateGlobalPersistsAsSharedSentinelRow(): void {
        $service = $this->makeService();

        $created = $service->createGlobal('Onboarding', 'icon-star', '#123456');

        $this->assertSame('Onboarding', $created->getName());
        $this->assertSame('', $created->getUserId());
        $this->assertTrue($created->getIsDefault());

        // Verify it round-trips as a real global default, not a per-user row.
        $globals = $service->findGlobalDefaults();
        $names = array_map(fn ($t) => $t->getName(), $globals);
        $this->assertContains('Onboarding', $names);
    }

    public function testUpdateGlobalCannotReachARegularUsersPersonalType(): void {
        // A per-user type happens to exist with some id. updateGlobal() must
        // report NotFound for it rather than silently renaming another
        // user's private note type — this is the admin-boundary AC.
        $victimId = $this->insertNoteType(['user_id' => 'alice', 'name' => 'Alice Private Type']);

        $service = $this->makeService();

        $this->expectException(NoteTypeNotFoundException::class);
        $service->updateGlobal($victimId, 'Hijacked By Admin Endpoint');
    }

    public function testDeleteGlobalCannotReachARegularUsersPersonalType(): void {
        $victimId = $this->insertNoteType(['user_id' => 'alice', 'name' => 'Alice Private Type']);

        $service = $this->makeService();

        $this->expectException(NoteTypeNotFoundException::class);
        $service->deleteGlobal($victimId);

        // The victim row must still exist untouched.
        $stillThere = $this->makeNoteTypeMapper()->findOwnedById($victimId, 'alice');
        $this->assertSame('Alice Private Type', $stillThere->getName());
    }

    public function testCountGlobalUsageCannotReachARegularUsersPersonalType(): void {
        // AC: the admin usage-check endpoint (GET .../note-types/{id}/usage)
        // must share the same authorization boundary as updateGlobal()/
        // deleteGlobal() — it must not return a real system-wide "notes using
        // this type" count for an id that names a regular user's private note
        // type rather than a real global default.
        $victimId = $this->insertNoteType(['user_id' => 'alice', 'name' => 'Alice Private Type']);
        $this->insertNote(['note_type_id' => $victimId, 'user_id' => 'alice']);

        $service = $this->makeService();

        $this->expectException(NoteTypeNotFoundException::class);
        $service->countGlobalUsage($victimId);
    }

    public function testUpdateGlobalActuallyPersistsChangeToTheGlobalRow(): void {
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Old Global', 'is_default' => 1, 'icon' => 'icon-phone', 'color' => '#000000']);

        $service = $this->makeService();
        $service->updateGlobal($id, 'New Global Name', 'icon-mail', '#ffffff');

        $reloaded = $this->makeNoteTypeMapper()->findGlobalById($id);
        $this->assertSame('New Global Name', $reloaded->getName());
        $this->assertSame('icon-mail', $reloaded->getIcon());
        $this->assertSame('#ffffff', $reloaded->getColor());
    }

    public function testDeleteGlobalActuallyRemovesTheRowWhenUnused(): void {
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Doomed Global', 'is_default' => 1]);

        $service = $this->makeService();
        $service->deleteGlobal($id);

        $this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
        $this->makeNoteTypeMapper()->findGlobalById($id);
    }

    public function testDeleteGlobalBlockedWhenReferencedByAnyUsersNoteAnywhereOnTheInstance(): void {
        // AC: deletion guard is SYSTEM-WIDE, not scoped to a single user's
        // notes (unlike the per-user delete() guard). Prove it with real rows
        // from THREE different users all pointing at the one global type.
        $id = $this->insertNoteType(['user_id' => '', 'name' => 'Popular Global', 'is_default' => 1]);
        $this->insertNote(['note_type_id' => $id, 'user_id' => 'alice']);
        $this->insertNote(['note_type_id' => $id, 'user_id' => 'bob']);
        $this->insertNote(['note_type_id' => $id, 'user_id' => 'carol']);

        $service = $this->makeService();

        $this->expectException(NoteTypeInUseException::class);
        $service->deleteGlobal($id);

        // Row must survive the blocked attempt.
        $stillThere = $this->makeNoteTypeMapper()->findGlobalById($id);
        $this->assertSame('Popular Global', $stillThere->getName());
    }

    public function testCreateGlobalRejectsNameOverColumnBoundEvenAgainstRealDb(): void {
        $service = $this->makeService();

        $this->expectException(NoteValidationException::class);
        $service->createGlobal(str_repeat('x', 129), 'icon-star', '#000000');
    }

    public function testGlobalTypeCreatedByAdminIsVisibleToAnyRegularUserViaFindAll(): void {
        // AC: "available to every user on this instance" — verify via the
        // regular-user read path (findAll), not just findGlobalDefaults().
        $service = $this->makeService();
        $service->createGlobal('Instance Wide Type', 'icon-star', '#0082c9');

        $result = $service->findAll('some-random-user-never-seen-before');
        $names = array_map(fn ($t) => $t->getName(), $result);
        $this->assertContains('Instance Wide Type', $names);
    }

    public function testGlobalTypeIsNotEditableThroughTheRegularPerUserUpdatePath(): void {
        // Cross-check the OTHER direction of the boundary: a regular user
        // must not be able to use the non-admin update() to mutate a global
        // default by guessing its id (findOwnedById excludes globals).
        $service = $this->makeService();
        $global = $service->createGlobal('Protected Global', 'icon-star', '#0082c9');

        $this->expectException(NoteTypeNotFoundException::class);
        $service->update($global->getId(), 'random-user', 'Renamed By Regular User');
    }
}
