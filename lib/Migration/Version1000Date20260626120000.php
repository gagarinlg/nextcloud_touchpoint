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
 * Consolidated initial schema for the CRM Notes app.
 *
 * This single step replaces the former incremental migrations (the original
 * create steps plus the later column/index adjustments). It creates every
 * crm_* table in its final shape, so a fresh install gets the complete schema
 * in one guarded, idempotent step. All boolean defaults are bound, lengths and
 * indexes match the released schema.
 */
class Version1000Date20260626120000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- crm_notes -------------------------------------------------------
        if (!$schema->hasTable('crm_notes')) {
            $t = $schema->createTable('crm_notes');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('addressbook_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('note_type_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('title', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('content', Types::TEXT, ['notnull' => false]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('is_pinned', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
            $t->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
            $t->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id'], 'crm_n_user_idx');
            $t->addIndex(['contact_uid'], 'crm_n_contact_idx');
            $t->addIndex(['addressbook_id'], 'crm_n_ab_idx');
            $t->addIndex(['note_type_id'], 'crm_n_type_idx');
            $t->addIndex(['created_at'], 'crm_n_created_idx');
            $t->addIndex(['contact_uid', 'user_id'], 'crm_n_contact_user_idx');
            $t->addIndex(['user_id', 'note_type_id'], 'crm_n_user_type_idx');
            $t->addIndex(['user_id', 'created_at'], 'crm_n_user_created_idx');
            $t->addIndex(['user_id', 'is_pinned', 'created_at'], 'crm_n_user_pin_created_idx');
        }

        // --- crm_note_types --------------------------------------------------
        if (!$schema->hasTable('crm_note_types')) {
            $t = $schema->createTable('crm_note_types');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
            $t->addColumn('icon', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => 'icon-note']);
            $t->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '#0082c9']);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('is_default', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id'], 'crm_nt_user_idx');
            // Global defaults use the empty-user_id sentinel; uniqueness is per
            // (owner, name) so a user's set and the shared global set don't clash.
            $t->addUniqueIndex(['user_id', 'name'], 'crm_nt_user_name_uniq');
        }

        // --- crm_note_contacts ----------------------------------------------
        if (!$schema->hasTable('crm_note_contacts')) {
            $t = $schema->createTable('crm_note_contacts');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            $t->addColumn('addressbook_id', Types::INTEGER, ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'crm_nc_note_idx');
            $t->addIndex(['contact_uid'], 'crm_nc_contact_idx');
            $t->addIndex(['contact_uid', 'addressbook_id'], 'crm_nc_contact_ab_idx');
            $t->addUniqueIndex(['note_id', 'contact_uid'], 'crm_nc_unique');
        }

        // --- crm_note_files --------------------------------------------------
        if (!$schema->hasTable('crm_note_files')) {
            $t = $schema->createTable('crm_note_files');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('file_id', Types::INTEGER, ['notnull' => false, 'default' => 0]);
            $t->addColumn('file_path', Types::STRING, ['notnull' => true, 'length' => 1024]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'crm_nf_note_idx');
            $t->addIndex(['file_id'], 'crm_nf_file_idx');
            $t->addUniqueIndex(['note_id', 'file_path'], 'crm_nf_path_unique');
        }

        // --- crm_note_sharing ------------------------------------------------
        if (!$schema->hasTable('crm_note_sharing')) {
            $t = $schema->createTable('crm_note_sharing');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('shared_with_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('shared_with_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('can_edit', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'crm_note_sharing_note_id');
            $t->addIndex(['shared_with_type', 'shared_with_id'], 'crm_note_sharing_with');
            $t->addUniqueIndex(['note_id', 'shared_with_type', 'shared_with_id'], 'crm_note_sharing_unique');
        }

        return $schema;
    }
}
