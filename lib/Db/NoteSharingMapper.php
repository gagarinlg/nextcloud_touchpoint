<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteSharing>
 */
class NoteSharingMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'crm_note_sharing', NoteSharing::class);
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
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in(
                'note_id',
                $qb->createNamedParameter($noteIds, IQueryBuilder::PARAM_INT_ARRAY)
            ));

        $map = [];
        foreach ($this->findEntities($qb) as $ns) {
            $map[$ns->getNoteId()][] = $ns;
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
            $this->insert($ns);
        }
    }
}
