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

class Version1000Date20260307000000 extends SimpleMigrationStep {

    #[\Override]
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_types')) {
            $table = $schema->createTable('crm_note_types');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => 'icon-note',
            ]);
            $table->addColumn('color', Types::STRING, [
                'notnull' => false,
                'length' => 7,
                'default' => '#0082c9',
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('is_default', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'crm_nt_user_idx');
        }

        if (!$schema->hasTable('crm_notes')) {
            $table = $schema->createTable('crm_notes');
            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
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
            $table->addColumn('note_type_id', Types::INTEGER, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('title', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('content', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('is_pinned', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['contact_uid'], 'crm_n_contact_idx');
            $table->addIndex(['user_id'], 'crm_n_user_idx');
            $table->addIndex(['note_type_id'], 'crm_n_type_idx');
            $table->addIndex(['addressbook_id'], 'crm_n_ab_idx');
            $table->addIndex(['created_at'], 'crm_n_created_idx');
        }

        return $schema;
    }
}
