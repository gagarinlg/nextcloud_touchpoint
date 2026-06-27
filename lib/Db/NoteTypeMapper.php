<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteType>
 */
class NoteTypeMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'touchpoint_note_types', NoteType::class);
    }

    /**
     * Build the READ scope: rows the user owns OR the shared global default set.
     *
     * Global defaults are the single instance-wide set of rows with an EMPTY
     * user_id and is_default = true — owned by no one. They are read-shared so
     * every user can see and select them, but never mutable: update/delete go
     * through findOwnedById(), which matches the caller's user_id only and so
     * excludes globals. This is safe against the historical cross-user IDOR
     * because only the '' sentinel — which no real user ever has — is OR-matched
     * alongside the caller's own id; one real user's id can never match another's.
     */
    private function readScope(IQueryBuilder $qb, string $userId) {
        return $qb->expr()->orX(
            $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
            $qb->expr()->andX(
                $qb->expr()->eq('user_id', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            )
        );
    }

    /**
     * The note types a user can see and select: their own plus the global
     * default set. See readScope() for the security rationale.
     *
     * @return NoteType[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($this->readScope($qb, $userId))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Read a note type the user owns OR a global default (read scope). Used for
     * display and to validate that a note may reference the type. Mutations must
     * use findOwnedById() instead.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findById(int $id, string $userId): NoteType {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            )
            ->andWhere($this->readScope($qb, $userId));

        return $this->findEntity($qb);
    }

    /**
     * The shared global default set (empty user_id, is_default = true). Used to
     * seed the defaults exactly once per instance.
     *
     * @return NoteType[]
     */
    public function findGlobalDefaults(): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter('', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            );

        return $this->findEntities($qb);
    }

    /**
     * Read a note type that the user *owns*. Global defaults are excluded, so
     * this is the correct lookup for mutations (update/delete) — it prevents one
     * user from editing another user's (or a shared default) type.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findOwnedById(int $id, string $userId): NoteType {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            )
            ->andWhere(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }
}
