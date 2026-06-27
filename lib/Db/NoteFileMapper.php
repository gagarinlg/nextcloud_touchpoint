<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<NoteFile>
 */
class NoteFileMapper extends QBMapper {

    /**
     * Maximum number of ids fed into a single IN(...) clause, mirroring
     * NoteMapper::IN_CHUNK_SIZE. Kept below the ~1000-element cap some DB
     * backends (notably Oracle) impose, so an unbounded note-id set is batched
     * into safe chunks rather than building one oversized query.
     */
    private const IN_CHUNK_SIZE = 900;

    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'touchpoint_note_files', NoteFile::class);
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

            foreach ($this->findEntities($qb) as $nf) {
                $map[$nf->getNoteId()][] = $nf;
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
