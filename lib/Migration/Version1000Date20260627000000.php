<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Initial schema for the Touchpoint app.
 *
 * A single, guarded, idempotent step that creates every touchpoint_* table in
 * its final shape, so a fresh install gets the complete schema at once. All
 * boolean defaults are bound; lengths and indexes are chosen to stay within the
 * InnoDB 3072-byte key limit on MySQL/MariaDB (utf8mb4).
 */
class Version1000Date20260627000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- touchpoint_notes ------------------------------------------------
        if (!$schema->hasTable('touchpoint_notes')) {
            $t = $schema->createTable('touchpoint_notes');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Default 0: Nextcloud's Entity setter skips marking a field dirty
            // when the value equals the property default (0 for int), so a note
            // created with addressbook_id 0 would otherwise omit the column and
            // hit the NOT NULL constraint. A DB default keeps that path safe.
            $t->addColumn('addressbook_id', Types::INTEGER, ['notnull' => true, 'default' => 0]);
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
            $t->addIndex(['user_id'], 'tp_n_user_idx');
            $t->addIndex(['contact_uid'], 'tp_n_contact_idx');
            $t->addIndex(['addressbook_id'], 'tp_n_ab_idx');
            $t->addIndex(['note_type_id'], 'tp_n_type_idx');
            $t->addIndex(['created_at'], 'tp_n_created_idx');
            $t->addIndex(['contact_uid', 'user_id'], 'tp_n_contact_user_idx');
            $t->addIndex(['user_id', 'note_type_id'], 'tp_n_user_type_idx');
            $t->addIndex(['user_id', 'created_at'], 'tp_n_user_created_idx');
            $t->addIndex(['user_id', 'is_pinned', 'created_at'], 'tp_n_user_pin_created_idx');
        }

        // --- touchpoint_note_types -------------------------------------------
        if (!$schema->hasTable('touchpoint_note_types')) {
            $t = $schema->createTable('touchpoint_note_types');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
            $t->addColumn('icon', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => 'icon-note']);
            $t->addColumn('color', Types::STRING, ['notnull' => false, 'length' => 32, 'default' => '#0082c9']);
            // Default '': the shared GLOBAL default types are stored with an empty
            // user_id, and Nextcloud's Entity setter omits a field whose value
            // equals its property default ('' for userId) — so without a DB default
            // the omitted column would violate NOT NULL when seedDefaults() inserts
            // the global set on a fresh install. (Same pattern as addressbook_id.)
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $t->addColumn('is_default', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id'], 'tp_nt_user_idx');
            // Global defaults use the empty-user_id sentinel; uniqueness is per
            // (owner, name) so a user's set and the shared global set don't clash.
            $t->addUniqueIndex(['user_id', 'name'], 'tp_nt_user_name_uniq');
        }

        // --- touchpoint_note_contacts ----------------------------------------
        if (!$schema->hasTable('touchpoint_note_contacts')) {
            $t = $schema->createTable('touchpoint_note_contacts');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('contact_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
            // Default 0: see touchpoint_notes.addressbook_id above (same Entity
            // setter / NOT NULL interaction).
            $t->addColumn('addressbook_id', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'tp_nc_note_idx');
            $t->addIndex(['contact_uid'], 'tp_nc_contact_idx');
            $t->addIndex(['contact_uid', 'addressbook_id'], 'tp_nc_contact_ab_idx');
            $t->addUniqueIndex(['note_id', 'contact_uid'], 'tp_nc_unique');
        }

        // --- touchpoint_note_files -------------------------------------------
        if (!$schema->hasTable('touchpoint_note_files')) {
            $t = $schema->createTable('touchpoint_note_files');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('file_id', Types::INTEGER, ['notnull' => false, 'default' => 0]);
            // VARCHAR(512), not 1024: file_path is part of the composite UNIQUE
            // index tp_nf_path_unique (note_id, file_path). On MySQL/MariaDB with
            // the Nextcloud-default utf8mb4 charset (4 bytes/char) a 1024 column
            // would make the indexed key ~1024*4 + 4 ≈ 4100 bytes, over InnoDB's
            // hard 3072-byte key limit, so a fresh install would fail the CREATE
            // TABLE with "Specified key was too long". At 512 the worst case is
            // 512*4 + 4 = 2052 bytes, comfortably under 3072.
            $t->addColumn('file_path', Types::STRING, ['notnull' => true, 'length' => 512]);
            $t->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'tp_nf_note_idx');
            $t->addIndex(['file_id'], 'tp_nf_file_idx');
            $t->addUniqueIndex(['note_id', 'file_path'], 'tp_nf_path_unique');
        }

        // --- touchpoint_note_sharing -----------------------------------------
        if (!$schema->hasTable('touchpoint_note_sharing')) {
            $t = $schema->createTable('touchpoint_note_sharing');
            $t->addColumn('id', Types::INTEGER, ['autoincrement' => true, 'notnull' => true]);
            $t->addColumn('note_id', Types::INTEGER, ['notnull' => true]);
            $t->addColumn('shared_with_type', Types::STRING, ['notnull' => true, 'length' => 16]);
            $t->addColumn('shared_with_id', Types::STRING, ['notnull' => true, 'length' => 64]);
            $t->addColumn('can_edit', Types::BOOLEAN, ['notnull' => true, 'default' => false]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['note_id'], 'tp_ns_note_idx');
            $t->addIndex(['shared_with_type', 'shared_with_id'], 'tp_ns_with_idx');
            $t->addUniqueIndex(['note_id', 'shared_with_type', 'shared_with_id'], 'tp_ns_unique');
        }

        return $schema;
    }
}
