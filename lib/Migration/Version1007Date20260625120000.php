<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The crm_note_files.file_path column was declared VARCHAR(4000) (Version1001)
 * and Version1002 added a unique index crm_nf_path_unique on
 * (note_id, file_path). On MySQL/MariaDB with the default utf8mb4 charset the
 * InnoDB max index key length is 3072 bytes, so a 4000-char utf8mb4 column makes
 * that unique index impossible to create — the migration aborts with
 * "Specified key was too long; max key length is 3072 bytes".
 *
 * This step shrinks file_path to an index-safe length (1024 chars) and then
 * (re)creates the unique index so the dedupe constraint NoteService::addFile
 * relies on actually exists on the most common Nextcloud DB backend.
 *
 * Shrinking a column on MySQL/MariaDB in STRICT mode (the modern default) aborts
 * the whole upgrade with a data-truncation error if any existing row holds a
 * file_path longer than the new length; on a non-strict server it would silently
 * truncate paths and break the (note_id, file_path) uniqueness semantics. To make
 * the ALTER safe, preSchemaChange() first removes any over-length rows (the file
 * link is unusable once truncated anyway, and the attachment can be re-added).
 */
class Version1007Date20260625120000 extends SimpleMigrationStep {

    /** Target length for crm_note_files.file_path (index-safe under InnoDB). */
    private const FILE_PATH_LENGTH = 1024;

    public function __construct(
        private IDBConnection $connection,
    ) {
    }

    /**
     * Remove rows whose file_path exceeds the new column length BEFORE the
     * schema change, so the changeColumn() below cannot abort the upgrade with a
     * truncation error and cannot silently corrupt a path.
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('crm_note_files')) {
            return;
        }

        $qb = $this->connection->getQueryBuilder();
        // CHAR_LENGTH() counts characters (not bytes), matching VARCHAR semantics.
        $qb->delete('crm_note_files')
            ->where(
                $qb->expr()->gt(
                    $qb->func()->charLength('file_path'),
                    $qb->createNamedParameter(self::FILE_PATH_LENGTH, IQueryBuilder::PARAM_INT)
                )
            );

        try {
            $deleted = $qb->executeStatement();
            if ($deleted > 0) {
                $output->warning(sprintf(
                    'crm_notes: removed %d crm_note_files row(s) with a file_path longer than %d characters before shrinking the column.',
                    $deleted,
                    self::FILE_PATH_LENGTH
                ));
            }
        } catch (\Throwable $e) {
            // Don't block the upgrade if the cleanup query itself fails (e.g. the
            // DB driver lacks CHAR_LENGTH under that name); the schema step may
            // still succeed on backends that aren't in STRICT mode.
            $output->warning('crm_notes: could not pre-clean over-length file_path rows: ' . $e->getMessage());
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_files')) {
            return null;
        }

        $table = $schema->getTable('crm_note_files');

        // Drop the (possibly-failed-to-create or oversized) unique index first so
        // we can safely shrink the column underneath it.
        if ($table->hasIndex('crm_nf_path_unique')) {
            $table->dropIndex('crm_nf_path_unique');
        }

        // Shrink file_path to a length that, even as utf8mb4 (4 bytes/char),
        // combined with note_id stays under the 3072-byte InnoDB key limit.
        if ($table->hasColumn('file_path')) {
            $table->changeColumn('file_path', [
                'notnull' => true,
                'length' => self::FILE_PATH_LENGTH,
            ]);
        }

        // Recreate the unique index now that the column is index-safe.
        if (!$table->hasIndex('crm_nf_path_unique')) {
            $table->addUniqueIndex(['note_id', 'file_path'], 'crm_nf_path_unique');
        }

        return $schema;
    }
}
