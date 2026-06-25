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

/**
 * Fix file_id column: make nullable and change unique index to use file_path.
 */
class Version1002Date20260311000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('crm_note_files')) {
            $table = $schema->getTable('crm_note_files');

            // Make file_id nullable so we can store files without a known file ID
            if ($table->hasColumn('file_id')) {
                $table->changeColumn('file_id', [
                    'type' => \Doctrine\DBAL\Types\Type::getType(Types::INTEGER),
                    'notnull' => false,
                    'default' => 0,
                ]);
            }

            // Remove the old unique index on (note_id, file_id) if it exists
            if ($table->hasIndex('crm_nf_unique')) {
                $table->dropIndex('crm_nf_unique');
            }

            // Add unique index on (note_id, file_path) instead
            if (!$table->hasIndex('crm_nf_path_unique')) {
                $table->addUniqueIndex(['note_id', 'file_path'], 'crm_nf_path_unique');
            }
        }

        return $schema;
    }
}
