<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Note>
 */
class NoteMapper extends QBMapper {

    /**
     * Maximum number of ids fed into a single IN(...) clause. Kept below the
     * ~1000-element cap that some DB backends (notably Oracle) impose, so id
     * lookups of an unbounded set (e.g. every note shared with a large group)
     * are batched into safe chunks rather than building one oversized query.
     */
    private const IN_CHUNK_SIZE = 900;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'crm_notes', Note::class);
    }

    /**
     * @return Note[]
     */
    public function findAll(string $userId, ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            // Stable final tiebreaker on the unique id so LIMIT/OFFSET paging is
            // deterministic when notes share updated_at/created_at; without it
            // tied rows can be reordered per query and duplicated/skipped across
            // an OFFSET boundary.
            ->addOrderBy('id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * Return all notes regardless of owner (public mode).
     *
     * @return Note[]
     */
    public function findAllPublic(?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            // Stable final tiebreaker on the unique id so LIMIT/OFFSET paging is
            // deterministic when notes share updated_at/created_at; without it
            // tied rows can be reordered per query and duplicated/skipped across
            // an OFFSET boundary.
            ->addOrderBy('id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findById(int $id, string $userId): Note {
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

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function findByIdPublic(int $id): Note {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * Load specific notes by their IDs.
     * Pass null for $userId to load without user scoping (public/shared context).
     *
     * @param int[] $ids
     * @return Note[]
     */
    public function findByIds(array $ids, ?string $userId): array {
        if (empty($ids)) {
            return [];
        }
        $entities = [];
        // Batch the IN(...) lookup so an unbounded id list never overflows a DB
        // backend's per-query placeholder/list limit.
        foreach (array_chunk(array_values($ids), self::IN_CHUNK_SIZE) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->in(
                    'id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
                ));

            if ($userId !== null) {
                $qb->andWhere(
                    $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
                );
            }

            foreach ($this->findEntities($qb) as $entity) {
                $entities[] = $entity;
            }
        }

        return $entities;
    }

    /**
     * Fetch only the id + sort-key columns for the given note IDs, so a caller
     * can build an ordered id window without materialising every full row.
     * The is_pinned flag is included so contact-scoped callers can apply their
     * pinned-first ordering on the id window too. Pass null for $userId to skip
     * owner scoping (shared/public context).
     *
     * @param int[] $ids
     * @return array<int, array{updated_at: ?string, created_at: ?string, is_pinned: bool}>  map of id => sort keys
     */
    public function findSortKeysByIds(array $ids, ?string $userId): array {
        if (empty($ids)) {
            return [];
        }
        $map = [];
        // Batch the IN(...) lookup so an unbounded id list (e.g. every note
        // shared with a large group) never overflows a DB backend's per-query
        // placeholder/list limit.
        foreach (array_chunk(array_values($ids), self::IN_CHUNK_SIZE) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id', 'updated_at', 'created_at', 'is_pinned')
                ->from($this->getTableName())
                ->where($qb->expr()->in(
                    'id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
                ));

            if ($userId !== null) {
                $qb->andWhere(
                    $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
                );
            }

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $map[(int)$row['id']] = [
                    'updated_at' => $row['updated_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'is_pinned' => (bool)$row['is_pinned'],
                ];
            }
            $result->closeCursor();
        }
        return $map;
    }

    /**
     * Load one ordered page of the notes a user can see: the notes they own
     * unioned with the explicitly-shared id set, ordered newest-first
     * (updated_at DESC, created_at DESC, id DESC) with the LIMIT/OFFSET window
     * applied in SQL. This pushes the ordering and paging down to the database
     * so a heavy user no longer loads and PHP-sorts their entire id/sort-key set
     * on every loadMore call.
     *
     * The shared id set is folded into the WHERE via an IN(...) clause. It is
     * bounded by what is shared with this user/groups; should it ever exceed a
     * backend's per-IN element cap the set is split into chunked OR(IN ...)
     * groups so the single ordered+windowed query is preserved.
     *
     * @param int[] $sharedIds note ids shared with the user (may be empty)
     * @return Note[]
     */
    public function findAccessiblePage(string $userId, array $sharedIds, ?int $limit = null, ?int $offset = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        $visible = $qb->expr()->orX(
            $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
        );
        foreach (array_chunk(array_values(array_unique($sharedIds)), self::IN_CHUNK_SIZE) as $chunk) {
            $visible->add($qb->expr()->in(
                'id',
                $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
            ));
        }

        $qb->where($visible)
            ->orderBy('updated_at', 'DESC')
            ->addOrderBy('created_at', 'DESC')
            // Stable final tiebreaker on the unique id so LIMIT/OFFSET paging is
            // deterministic when notes share updated_at/created_at.
            ->addOrderBy('id', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }
        if ($offset !== null) {
            $qb->setFirstResult($offset);
        }

        return $this->findEntities($qb);
    }

    /**
     * Count notes that reference a given note type (optionally scoped to owner).
     */
    public function countByNoteType(int $noteTypeId, ?string $userId = null): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('note_type_id', $qb->createNamedParameter($noteTypeId, IQueryBuilder::PARAM_INT))
            );

        if ($userId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        }

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * @return Note[]
     */
    public function findByContact(string $contactUid, ?string $userId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('is_pinned', 'DESC')
            ->addOrderBy('updated_at', 'DESC')
            ->addOrderBy('created_at', 'DESC');

        if ($userId !== null) {
            $qb->andWhere(
                $qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            );
        }

        return $this->findEntities($qb);
    }
}
