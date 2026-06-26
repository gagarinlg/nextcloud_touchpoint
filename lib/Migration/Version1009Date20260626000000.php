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
     */
    #[\Override]
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('crm_note_types')) {
            return;
        }

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
            // Delete every row in the group except the surviving (lowest-id) one.
            $del = $this->db->getQueryBuilder();
            $del->delete('crm_note_types')
                ->where($del->expr()->eq('user_id', $del->createNamedParameter($row['user_id'])))
                ->andWhere($del->expr()->eq('name', $del->createNamedParameter($row['name'])))
                ->andWhere($del->expr()->neq('id', $del->createNamedParameter((int) $row['keep_id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
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
