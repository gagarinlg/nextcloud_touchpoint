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
 * Add a per-share permission column so we can distinguish read-only share
 * recipients from those allowed to edit/delete a note. Existing shares default
 * to read-only (can_edit = false); only the note owner has implicit write.
 */
class Version1006Date20260625000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('crm_note_sharing')) {
            $table = $schema->getTable('crm_note_sharing');

            if (!$table->hasColumn('can_edit')) {
                $table->addColumn('can_edit', Types::BOOLEAN, [
                    'notnull' => true,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}
