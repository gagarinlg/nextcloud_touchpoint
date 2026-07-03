<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Base class for Unit/Db mapper tests that exercise the SHIPPED mapper code
 * against a real in-memory SQLite database, instead of mocking IDBConnection/
 * IQueryBuilder.
 *
 * Why real SQLite instead of mocks
 * ---------------------------------
 * Mocking IQueryBuilder only proves that the mapper CALLS certain builder
 * methods with certain arguments; it cannot prove that the resulting SQL is
 * correctly scoped (e.g. that a visibility predicate and a search predicate
 * are properly ANDed, not flattened into an OR that leaks another user's
 * rows). Running the exact same mapper code against a live SQLite engine
 * closes that gap: a regression that mis-parenthesises a WHERE clause changes
 * the rows returned, which these tests can observe directly.
 *
 * This reuses the PDO-backed IQueryBuilder/IExpressionBuilder adapter that
 * already exists for the integration suite
 * (tests/Integration/Db/PdoQueryBuilder.php: PdoDbConnection/PdoQueryBuilder/
 * PdoExpressionBuilder) rather than re-implementing query rendering a second
 * time. That adapter renders the production expr()/where()/andWhere()/orX()/
 * andX() composition to real SQL; it has NO knowledge of Touchpoint's
 * visibility/search semantics. Its classes are declared `final`, so this file
 * uses them directly (composition) rather than subclassing them.
 *
 * Additions over the integration adapter
 * ---------------------------------------
 * The integration suite only ever SELECTs, so PdoQueryBuilder::delete() and
 * ::executeStatement() intentionally throw. NoteSharingMapper::syncSharing()
 * (via deleteByNoteId()) needs a real DELETE, and QBMapper::insert() needs a
 * real INSERT (with UNIQUE-constraint translation to OCP\DB\Exception so
 * syncSharing()'s duplicate-tolerance branch is exercised for real). Both are
 * implemented here with plain PDO statements against the same in-memory
 * database, bypassing PdoQueryBuilder entirely for writes — simpler than
 * extending a `final` class, and every SELECT path still goes through the
 * shared, already-audited adapter.
 *
 * Table schemas mirror the production migrations closely enough for the
 * mapper methods under test (column names/order do not need to match the
 * migration's DEFAULT/NOT NULL exactly, only the columns the mappers read
 * and write).
 */

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteSharing;
use OCA\Touchpoint\Db\NoteSharingMapper;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Db\NoteTypeMapper;
use OCA\Touchpoint\Tests\Integration\Db\PdoDbConnection;
use OCP\AppFramework\Db\Entity;
use PDO;
use PHPUnit\Framework\TestCase;

// The PDO-backed query-builder adapter (PdoQueryBuilder/PdoExpressionBuilder/
// PdoDbConnection) lives in the integration suite and is not part of any PSR-4
// autoload path here; require it explicitly, same as the integration tests do.
require_once __DIR__ . '/../../Integration/Db/PdoQueryBuilder.php';

/**
 * NoteMapper subclass that hydrates full Note rows from real SQLite results
 * (the stub QBMapper::findEntities()/findEntity() otherwise short-circuit to
 * [] / an empty entity, ignoring the query entirely).
 *
 * @extends NoteMapper
 */
class SqliteNoteMapper extends NoteMapper {
    /**
     * @param \OCA\Touchpoint\Tests\Integration\Db\PdoQueryBuilder $query
     * @return Note[]
     */
    protected function findEntities($query): array {
        $rows = $query->executeQuery()->fetchAll();
        return array_map([self::class, 'hydrate'], $rows);
    }

    /**
     * @param \OCA\Touchpoint\Tests\Integration\Db\PdoQueryBuilder $query
     */
    protected function findEntity($query): Note {
        $rows = $query->executeQuery()->fetchAll();
        if (count($rows) === 0) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('No matching note found');
        }
        if (count($rows) > 1) {
            throw new \OCP\AppFramework\Db\MultipleObjectsReturnedException('More than one result');
        }
        return self::hydrate($rows[0]);
    }

    public static function hydrate(array $row): Note {
        $note = new Note();
        $note->setId((int) $row['id']);
        $note->setContactUid((string) ($row['contact_uid'] ?? ''));
        $note->setAddressbookId((int) ($row['addressbook_id'] ?? 0));
        $note->setNoteTypeId((int) ($row['note_type_id'] ?? 0));
        $note->setTitle((string) ($row['title'] ?? ''));
        $note->setContent($row['content'] ?? null);
        $note->setUserId((string) ($row['user_id'] ?? ''));
        // SQLite has no native BOOLEAN; is_pinned round-trips as 0/1.
        $note->setIsPinned(in_array($row['is_pinned'] ?? 0, [true, 1, '1', 't'], true));
        $note->resetUpdatedFields();
        return $note;
    }
}

/**
 * NoteTypeMapper subclass that hydrates full NoteType rows from real SQLite
 * results.
 *
 * @extends NoteTypeMapper
 */
class SqliteNoteTypeMapper extends NoteTypeMapper {
    /** @return NoteType[] */
    protected function findEntities($query): array {
        $rows = $query->executeQuery()->fetchAll();
        return array_map([self::class, 'hydrate'], $rows);
    }

    protected function findEntity($query): NoteType {
        $rows = $query->executeQuery()->fetchAll();
        if (count($rows) === 0) {
            throw new \OCP\AppFramework\Db\DoesNotExistException('No matching note type found');
        }
        if (count($rows) > 1) {
            throw new \OCP\AppFramework\Db\MultipleObjectsReturnedException('More than one result');
        }
        return self::hydrate($rows[0]);
    }

    public static function hydrate(array $row): NoteType {
        $type = new NoteType();
        $type->setId((int) $row['id']);
        $type->setName((string) ($row['name'] ?? ''));
        $type->setIcon($row['icon'] ?? null);
        $type->setColor($row['color'] ?? null);
        $type->setUserId((string) ($row['user_id'] ?? ''));
        $type->setIsDefault(in_array($row['is_default'] ?? 0, [true, 1, '1', 't'], true));
        $type->resetUpdatedFields();
        return $type;
    }
}

/**
 * NoteSharingMapper subclass that hydrates real rows and performs real
 * INSERT/DELETE against the fixture table (bypassing the SELECT-only
 * PdoQueryBuilder for writes — see file header), so syncSharing() can be
 * exercised end-to-end (delete + re-insert + unique-constraint handling)
 * against SQLite.
 *
 * @extends NoteSharingMapper
 */
class SqliteNoteSharingMapper extends NoteSharingMapper {

    public function __construct(private PDO $pdo, PdoDbConnection $db) {
        parent::__construct($db);
    }

    /** @return NoteSharing[] */
    protected function findEntities($query): array {
        $rows = $query->executeQuery()->fetchAll();
        return array_map([self::class, 'hydrate'], $rows);
    }

    public static function hydrate(array $row): NoteSharing {
        $ns = new NoteSharing();
        $ns->setId((int) $row['id']);
        $ns->setNoteId((int) $row['note_id']);
        $ns->setSharedWithType((string) $row['shared_with_type']);
        $ns->setSharedWithId((string) $row['shared_with_id']);
        $ns->setCanEdit(in_array($row['can_edit'] ?? 0, [true, 1, '1', 't'], true));
        $ns->resetUpdatedFields();
        return $ns;
    }

    /**
     * Real DELETE against the fixture table. QBMapper's own delete()/query
     * builder plumbing is bypassed here (same rationale as insert() below);
     * deleteByNoteId() is re-implemented directly with the mapper's actual SQL
     * shape ("WHERE note_id = ?").
     */
    public function deleteByNoteId(int $noteId): void {
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . $this->getTableName() . ' WHERE note_id = :note_id'
        );
        $stmt->execute([':note_id' => $noteId]);
    }

    /**
     * Real INSERT against the fixture table, so syncSharing()'s unique-index
     * violation handling can be exercised with an actual SQLite UNIQUE
     * constraint rather than a mocked exception.
     */
    public function insert(Entity $entity): Entity {
        /** @var NoteSharing $entity */
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . $this->getTableName() . '
                    (note_id, shared_with_type, shared_with_id, can_edit)
                 VALUES (:note_id, :type, :id, :can_edit)'
            );
            $stmt->execute([
                ':note_id'  => $entity->getNoteId(),
                ':type'     => $entity->getSharedWithType(),
                ':id'       => $entity->getSharedWithId(),
                ':can_edit' => $entity->getCanEdit() ? 1 : 0,
            ]);
        } catch (\PDOException $e) {
            // SQLite reports a UNIQUE constraint violation with the string
            // "UNIQUE constraint failed" in the driver message. Translate it
            // to the OCP\DB\Exception shape production code (and
            // syncSharing()) expects.
            if (str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                $dbEx = new \OCP\DB\Exception($e->getMessage(), 0, $e);
                $dbEx->setReason(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
                throw $dbEx;
            }
            throw $e;
        }

        $entity->setId((int) $this->pdo->lastInsertId());
        return $entity;
    }
}

abstract class SqliteTestCase extends TestCase {

    protected PDO $pdo;
    protected PdoDbConnection $db;

    protected function setUp(): void {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $this->pdo->exec(
            'CREATE TABLE touchpoint_notes (
                id             INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_uid    TEXT NOT NULL DEFAULT \'\',
                addressbook_id INTEGER NOT NULL DEFAULT 0,
                note_type_id   INTEGER NOT NULL DEFAULT 0,
                title          TEXT NOT NULL DEFAULT \'\',
                content        TEXT,
                user_id        TEXT NOT NULL,
                is_pinned      INTEGER NOT NULL DEFAULT 0,
                created_at     TEXT NOT NULL DEFAULT (datetime(\'now\')),
                updated_at     TEXT NOT NULL DEFAULT (datetime(\'now\')),
                created_by     TEXT,
                updated_by     TEXT
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE touchpoint_note_types (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL DEFAULT \'\',
                icon        TEXT,
                color       TEXT,
                user_id     TEXT NOT NULL DEFAULT \'\',
                is_default  INTEGER NOT NULL DEFAULT 0
            )'
        );

        $this->pdo->exec(
            'CREATE TABLE touchpoint_note_sharing (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                note_id           INTEGER NOT NULL,
                shared_with_type  TEXT NOT NULL,
                shared_with_id    TEXT NOT NULL,
                can_edit          INTEGER NOT NULL DEFAULT 0,
                UNIQUE (note_id, shared_with_type, shared_with_id)
            )'
        );

        $this->db = new PdoDbConnection($this->pdo, 'sqlite');
    }

    protected function tearDown(): void {
        $this->pdo->exec('DELETE FROM touchpoint_notes');
        $this->pdo->exec('DELETE FROM touchpoint_note_types');
        $this->pdo->exec('DELETE FROM touchpoint_note_sharing');
    }

    protected function makeNoteMapper(): SqliteNoteMapper {
        return new SqliteNoteMapper($this->db);
    }

    protected function makeNoteTypeMapper(): SqliteNoteTypeMapper {
        return new SqliteNoteTypeMapper($this->db);
    }

    protected function makeNoteSharingMapper(): SqliteNoteSharingMapper {
        return new SqliteNoteSharingMapper($this->pdo, $this->db);
    }

    /**
     * Insert a note fixture row and return its generated id.
     */
    protected function insertNote(array $overrides = []): int {
        $defaults = [
            'contact_uid'    => '',
            'addressbook_id' => 0,
            'note_type_id'   => 1,
            'title'          => 'note title',
            'content'        => null,
            'user_id'        => 'user1',
            'is_pinned'      => 0,
            'created_at'     => '2026-01-01 00:00:00',
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO touchpoint_notes
                (contact_uid, addressbook_id, note_type_id, title, content, user_id, is_pinned, created_at)
             VALUES (:contact_uid, :addressbook_id, :note_type_id, :title, :content, :user_id, :is_pinned, :created_at)'
        );
        $stmt->execute([
            ':contact_uid'    => $row['contact_uid'],
            ':addressbook_id' => $row['addressbook_id'],
            ':note_type_id'   => $row['note_type_id'],
            ':title'          => $row['title'],
            ':content'        => $row['content'],
            ':user_id'        => $row['user_id'],
            ':is_pinned'      => $row['is_pinned'] ? 1 : 0,
            ':created_at'     => $row['created_at'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a note-type fixture row and return its generated id.
     */
    protected function insertNoteType(array $overrides = []): int {
        $defaults = [
            'name'       => 'Type',
            'icon'       => 'icon-category-office',
            'color'      => '#0082c9',
            'user_id'    => 'user1',
            'is_default' => 0,
        ];
        $row = array_merge($defaults, $overrides);

        $stmt = $this->pdo->prepare(
            'INSERT INTO touchpoint_note_types (name, icon, color, user_id, is_default)
             VALUES (:name, :icon, :color, :user_id, :is_default)'
        );
        $stmt->execute([
            ':name'       => $row['name'],
            ':icon'       => $row['icon'],
            ':color'      => $row['color'],
            ':user_id'    => $row['user_id'],
            ':is_default' => $row['is_default'] ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a note-sharing fixture row and return its generated id.
     */
    protected function insertSharing(int $noteId, string $type, string $sharedWithId, bool $canEdit = false): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO touchpoint_note_sharing (note_id, shared_with_type, shared_with_id, can_edit)
             VALUES (:note_id, :type, :id, :can_edit)'
        );
        $stmt->execute([
            ':note_id'  => $noteId,
            ':type'     => $type,
            ':id'       => $sharedWithId,
            ':can_edit' => $canEdit ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Count the number of rows currently in touchpoint_note_sharing for a
     * given note id. Useful for asserting syncSharing()'s replace semantics.
     */
    protected function countSharingRows(int $noteId): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM touchpoint_note_sharing WHERE note_id = :note_id');
        $stmt->execute([':note_id' => $noteId]);
        return (int) $stmt->fetchColumn();
    }
}
