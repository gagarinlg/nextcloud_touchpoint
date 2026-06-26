<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteContact>
 */
class NoteContactMapper extends QBMapper {

    /**
     * Maximum number of ids fed into a single IN(...) clause, mirroring
     * NoteMapper::IN_CHUNK_SIZE. Kept below the ~1000-element cap some DB
     * backends (notably Oracle) impose, so an unbounded note-id set (e.g. every
     * note linked to one contact across all users, via NoteService::findByContact)
     * is batched into safe chunks rather than building one oversized query.
     */
    private const IN_CHUNK_SIZE = 900;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'crm_note_contacts', NoteContact::class);
    }

    /**
     * @return NoteContact[]
     */
    public function findByNoteId(int $noteId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('note_id', $qb->createNamedParameter($noteId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * @return NoteContact[]
     */
    public function findByContactUid(string $contactUid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_uid', $qb->createNamedParameter($contactUid, IQueryBuilder::PARAM_STR)));

        return $this->findEntities($qb);
    }

    /**
     * Load contacts for multiple notes in a single query.
     *
     * @param int[] $noteIds
     * @return array<int, NoteContact[]>  map of note_id => NoteContact[]
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

            foreach ($this->findEntities($qb) as $nc) {
                $map[$nc->getNoteId()][] = $nc;
            }
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
