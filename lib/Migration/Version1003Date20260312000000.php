<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add composite indices for faster note listing, search, and per-contact queries.
 */
class Version1003Date20260312000000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('crm_notes')) {
            $table = $schema->getTable('crm_notes');

            // Composite index: (user_id, created_at) — covers the default sorted listing per user
            if (!$table->hasIndex('crm_n_user_created_idx')) {
                $table->addIndex(['user_id', 'created_at'], 'crm_n_user_created_idx');
            }

            // Composite index: (contact_uid, user_id) — covers per-contact note lookup
            if (!$table->hasIndex('crm_n_contact_user_idx')) {
                $table->addIndex(['contact_uid', 'user_id'], 'crm_n_contact_user_idx');
            }

            // Composite index: (user_id, note_type_id) — covers filtering by type
            if (!$table->hasIndex('crm_n_user_type_idx')) {
                $table->addIndex(['user_id', 'note_type_id'], 'crm_n_user_type_idx');
            }

            // Composite index: (user_id, is_pinned, created_at) — covers pinned-first sort
            if (!$table->hasIndex('crm_n_user_pin_created_idx')) {
                $table->addIndex(['user_id', 'is_pinned', 'created_at'], 'crm_n_user_pin_created_idx');
            }
        }

        if ($schema->hasTable('crm_note_contacts')) {
            $table = $schema->getTable('crm_note_contacts');

            // Composite index: (contact_uid, addressbook_id) — covers contact-scoped lookups
            if (!$table->hasIndex('crm_nc_contact_ab_idx')) {
                $table->addIndex(['contact_uid', 'addressbook_id'], 'crm_nc_contact_ab_idx');
            }
        }

        return $schema;
    }
}
