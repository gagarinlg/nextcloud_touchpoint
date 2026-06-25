<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1005Date20260312120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_sharing')) {
            $table = $schema->createTable('crm_note_sharing');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('note_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('shared_with_type', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);
            $table->addColumn('shared_with_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['note_id'], 'crm_note_sharing_note_id');
            $table->addIndex(['shared_with_type', 'shared_with_id'], 'crm_note_sharing_with');
            $table->addUniqueIndex(
                ['note_id', 'shared_with_type', 'shared_with_id'],
                'crm_note_sharing_unique'
            );
        }

        return $schema;
    }
}
