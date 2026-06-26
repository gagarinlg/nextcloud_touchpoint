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
 * Bring crm_note_files.file_path into a portable, index-safe shape on any
 * instance that ran an earlier baseline.
 *
 * An earlier revision of the consolidated baseline
 * (Version1000Date20260626120000) declared file_path as VARCHAR(1024) and made
 * it part of the composite UNIQUE index crm_nf_path_unique on
 * (note_id, file_path). On MySQL/MariaDB with the Nextcloud-default utf8mb4
 * charset every character can take up to 4 bytes, so the indexed key length is
 * ~1024*4 + 4 (the INT note_id) ≈ 4100 bytes — over InnoDB's hard 3072-byte
 * index key-length limit, so a fresh MySQL/MariaDB install would fail the
 * CREATE TABLE with "Specified key was too long" (SQLite/Postgres were
 * unaffected, which is why it went unnoticed). The baseline now creates the
 * column at VARCHAR(512) directly, so fresh installs are safe at create time.
 *
 * This step still repairs instances that were created against the old 1024
 * baseline by shortening file_path to VARCHAR(512). Worst case under utf8mb4
 * the composite key is 512*4 + 4 = 2052 bytes, comfortably under 3072. A user
 * folder-relative path (the only thing ever stored here) never approaches even
 * 512 characters, so no real data is at risk of truncation. On a fresh install
 * (column already 512, index already in place) this step is a no-op.
 *
 * The step is fully guarded/idempotent: it only touches an existing table,
 * drops the unique index before changing the column length (some DB platforms
 * refuse to alter a column that is part of an index), shortens the column, then
 * recreates the unique index — each guarded by a hasIndex()/getColumn() check so
 * re-running on an already-migrated instance is a no-op.
 */
class Version1001Date20260626130000 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_files')) {
            return null;
        }

        $table = $schema->getTable('crm_note_files');
        $changed = false;

        // Drop the over-long unique index first so the column can be altered on
        // platforms that forbid changing an indexed column in place.
        if ($table->hasIndex('crm_nf_path_unique')) {
            $table->dropIndex('crm_nf_path_unique');
            $changed = true;
        }

        // Shorten file_path to a length whose worst-case utf8mb4 byte size plus
        // the INT note_id fits well under InnoDB's 3072-byte index key limit.
        if ($table->hasColumn('file_path')) {
            $column = $table->getColumn('file_path');
            if ($column->getLength() === null || $column->getLength() > 512) {
                $column->setLength(512);
                $changed = true;
            }
        }

        // Recreate the (now index-safe) composite unique index.
        if (!$table->hasIndex('crm_nf_path_unique')) {
            $table->addUniqueIndex(['note_id', 'file_path'], 'crm_nf_path_unique');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
