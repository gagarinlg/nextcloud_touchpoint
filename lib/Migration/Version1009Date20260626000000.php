<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Make global-default seeding race-free at the data layer.
 *
 * NoteTypeService::seedDefaults() guards its 5 inserts with a check-then-insert
 * (`if findGlobalDefaults() is empty`). crm_note_types had no unique constraint
 * (migrations 1000–1008), so two concurrent first requests on a fresh instance
 * could both observe zero globals and each insert the 5 rows, leaving 10
 * duplicate global types in every user's picker forever, with no later dedupe.
 *
 * This step adds a UNIQUE index on (user_id, name). A note-type name is unique
 * per owner (and within the shared global set, whose user_id is the '' sentinel)
 * — duplicate names were never meaningful — so the constraint is the race-free
 * guard: a concurrent second seed now fails the duplicate INSERT, which
 * seedDefaults() catches and ignores (REASON_UNIQUE_CONSTRAINT_VIOLATION),
 * exactly as create()/addFile already handle junction dupes.
 *
 * Pre-existing duplicates (from a fresh instance that already double-seeded
 * before this migration) are removed in preSchemaChange so the unique index can
 * be created cleanly.
 */
class Version1009Date20260626000000 extends SimpleMigrationStep {

    private const INDEX_NAME = 'crm_nt_user_name_uniq';

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Remove any duplicate (user_id, name) rows before the unique index is
     * created, keeping the lowest id of each group. Without this, createUnique
     * could fail on an instance that already double-seeded.
     *
     * Because crm_note_types had no unique constraint before this migration, a
     * user could legitimately have created two distinct types sharing a name
     * (e.g. different color/icon) and attached notes to the higher-id one. There
     * are no DB-level foreign keys or cascades (per CLAUDE.md), so deleting a
     * non-surviving row would silently orphan every note whose note_type_id
     * pointed at it — the badge would no longer resolve. To preserve those
     * notes we first re-point them at the surviving keep_id, then delete the
     * duplicate row.
     */
    #[\Override]
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('crm_note_types')) {
            return;
        }
        $hasNotesTable = $schema->hasTable('crm_notes');

        // Find (user_id, name) groups that have more than one row.
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'name')
            ->selectAlias($qb->func()->min('id'), 'keep_id')
            ->from('crm_note_types')
            ->groupBy('user_id', 'name')
            ->having($qb->expr()->gt(
                $qb->func()->count('id'),
                $qb->createNamedParameter(1, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)
            ));
        $result = $qb->executeQuery();
        $dupes = $result->fetchAll();
        $result->closeCursor();

        foreach ($dupes as $row) {
            $keepId = (int) $row['keep_id'];

            // Identify the non-surviving ids in this (user_id, name) group so we
            // can both re-point referencing notes and delete the rows.
            $idsQb = $this->db->getQueryBuilder();
            $idsQb->select('id')
                ->from('crm_note_types')
                ->where($idsQb->expr()->eq('user_id', $idsQb->createNamedParameter($row['user_id'])))
                ->andWhere($idsQb->expr()->eq('name', $idsQb->createNamedParameter($row['name'])))
                ->andWhere($idsQb->expr()->neq('id', $idsQb->createNamedParameter($keepId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $idsResult = $idsQb->executeQuery();
            $deleteIds = array_map(static fn ($r) => (int) $r['id'], $idsResult->fetchAll());
            $idsResult->closeCursor();

            if (empty($deleteIds)) {
                continue;
            }

            // Re-point every note referencing a soon-to-be-deleted type at the
            // surviving keep_id so no note_type_id is orphaned. There is no
            // cascade, so this MUST happen before the delete below.
            if ($hasNotesTable) {
                $repoint = $this->db->getQueryBuilder();
                $repoint->update('crm_notes')
                    ->set('note_type_id', $repoint->createNamedParameter($keepId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->where($repoint->expr()->in(
                        'note_type_id',
                        $repoint->createNamedParameter($deleteIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                    ));
                $repoint->executeStatement();
            }

            // Now delete the duplicate rows; their referencing notes (if any)
            // have been re-pointed at keep_id above.
            $del = $this->db->getQueryBuilder();
            $del->delete('crm_note_types')
                ->where($del->expr()->in(
                    'id',
                    $del->createNamedParameter($deleteIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
                ));
            $del->executeStatement();
        }
    }

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_types')) {
            return null;
        }

        $table = $schema->getTable('crm_note_types');
        if (!$table->hasIndex(self::INDEX_NAME)) {
            $table->addUniqueIndex(['user_id', 'name'], self::INDEX_NAME);
        }

        return $schema;
    }
}
