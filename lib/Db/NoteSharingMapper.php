<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteSharing>
 */
class NoteSharingMapper extends QBMapper {

    /**
     * Maximum number of ids fed into a single IN(...) clause, mirroring
     * NoteMapper::IN_CHUNK_SIZE. Kept below the ~1000-element cap some DB
     * backends (notably Oracle) impose, so an unbounded note-id set is batched
     * into safe chunks rather than building one oversized query.
     */
    private const IN_CHUNK_SIZE = 900;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'touchpoint_note_sharing', NoteSharing::class);
    }

    /**
     * @return NoteSharing[]
     */
    public function findByNoteId(int $noteId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * @param int[] $noteIds
     * @return array<int, NoteSharing[]>  map of note_id => NoteSharing[]
     */
    public function findByNoteIds(array $noteIds): array {
        if (empty($noteIds)) {
            return [];
        }
        $map = [];
        // Batch the IN(...) lookup so an unbounded id list never overflows a DB
        // backend's per-query placeholder/list limit (matches NoteMapper).
        foreach (array_chunk(array_values($noteIds), self::IN_CHUNK_SIZE) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('*')
                ->from($this->getTableName())
                ->where($qb->expr()->in(
                    'note_id',
                    $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)
                ));

            foreach ($this->findEntities($qb) as $ns) {
                $map[$ns->getNoteId()][] = $ns;
            }
        }
        return $map;
    }

    /**
     * Return all note IDs accessible to this user (via direct or group share).
     *
     * @param string[] $groupIds
     * @return int[]
     */
    public function findAccessibleNoteIds(string $userId, array $groupIds): array {
        return $this->queryNoteIds($userId, $groupIds, false);
    }

    /**
     * Return note IDs this user is allowed to edit/delete via an explicit
     * write share (can_edit = true). Owner access is handled separately.
     *
     * @param string[] $groupIds
     * @return int[]
     */
    public function findWritableNoteIds(string $userId, array $groupIds): array {
        return $this->queryNoteIds($userId, $groupIds, true);
    }

    /**
     * @param string[] $groupIds
     * @return int[]
     */
    private function queryNoteIds(string $userId, array $groupIds, bool $writeOnly): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('note_id')
            ->from($this->getTableName());

        $orX = $qb->expr()->orX(
            $qb->expr()->andX(
                $qb->expr()->eq('shared_with_type', $qb->createNamedParameter('user', IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('shared_with_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
            )
        );

        if (!empty($groupIds)) {
            $orX->add(
                $qb->expr()->andX(
                    $qb->expr()->eq('shared_with_type', $qb->createNamedParameter('group', IQueryBuilder::PARAM_STR)),
                    $qb->expr()->in('shared_with_id', $qb->createNamedParameter($groupIds, IQueryBuilder::PARAM_STR_ARRAY))
                )
            );
        }

        $qb->where($orX);

        if ($writeOnly) {
            $qb->andWhere(
                $qb->expr()->eq('can_edit', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
            );
        }

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['note_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    public function deleteByNoteId(int $noteId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Replace all sharing entries for a note.
     *
     * @param array<array{type: string, id: string, canEdit?: bool}> $targets
     */
    public function syncSharing(int $noteId, array $targets): void {
        $this->deleteByNoteId($noteId);
        foreach ($targets as $target) {
            $ns = new NoteSharing();
            $ns->setNoteId($noteId);
            $ns->setSharedWithType($target['type']);
            $ns->setSharedWithId($target['id']);
            $ns->setCanEdit(!empty($target['canEdit']));
            try {
                $this->insert($ns);
            } catch (DBException $e) {
                // Tolerate a duplicate (note_id, type, id) row racing in past the
                // caller-side dedupe — the unique index guarantees the principal
                // is already shared with the same target, so swallowing the
                // violation keeps the ACL correct rather than 500-ing. Mirrors
                // NoteService::create()/addFile(). Anything else propagates.
                if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
            }
        }
    }
}
