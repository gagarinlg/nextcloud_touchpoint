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

class Version1004Date20260312100000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Guard consistently with the other steps: if the base table is somehow
        // absent (out-of-order replay, partial rollback) there is nothing to
        // alter, so bail out cleanly instead of throwing.
        if (!$schema->hasTable('crm_notes')) {
            return null;
        }

        $table = $schema->getTable('crm_notes');

        if (!$table->hasColumn('created_by')) {
            $table->addColumn('created_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('updated_by')) {
            $table->addColumn('updated_by', Types::STRING, [
                'notnull' => false,
                'length'  => 64,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
