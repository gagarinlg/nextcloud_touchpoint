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

class Version1001Date20260307120000 extends SimpleMigrationStep {

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Junction table: link notes to multiple contacts
        if (!$schema->hasTable('crm_note_contacts')) {
            $table = $schema->createTable('crm_note_contacts');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('note_id', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('contact_uid', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('addressbook_id', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['note_id'], 'crm_nc_note_idx');
            $table->addIndex(['contact_uid'], 'crm_nc_contact_idx');
            $table->addUniqueIndex(['note_id', 'contact_uid'], 'crm_nc_unique');
        }

        // File attachments table
        if (!$schema->hasTable('crm_note_files')) {
            $table = $schema->createTable('crm_note_files');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('note_id', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('file_id', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('file_path', Types::STRING, [
                'notnull' => true,
                'length' => 4000,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['note_id'], 'crm_nf_note_idx');
            $table->addIndex(['file_id'], 'crm_nf_file_idx');
            $table->addUniqueIndex(['note_id', 'file_id'], 'crm_nf_unique');
        }

        return $schema;
    }
}
