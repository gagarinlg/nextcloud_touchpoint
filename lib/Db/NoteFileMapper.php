<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteFile>
 */
class NoteFileMapper extends QBMapper {

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'crm_note_files', NoteFile::class);
    }

    /**
     * @return NoteFile[]
     */
    public function findByNoteId(int $noteId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Load files for multiple notes in a single query.
     *
     * @param int[] $noteIds
     * @return array<int, NoteFile[]>  map of note_id => NoteFile[]
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
        foreach ($this->findEntities($qb) as $nf) {
            $map[$nf->getNoteId()][] = $nf;
        }
        return $map;
    }

    public function deleteByNoteId(int $noteId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
