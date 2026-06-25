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
 * The crm_note_types.color column was declared VARCHAR(7) (Version1000), which
 * only fits a '#rrggbb' value. However NoteTypeService::normalizeColor()
 * deliberately accepts and persists hsl()/hsla() values (e.g.
 * 'hsl(120, 50%, 50%)', 18+ chars) because the frontend (safeColor /
 * readableTextColor) renders them — and create()/update() then call setColor()
 * with that value. On MySQL/MariaDB in STRICT mode (the modern default) the
 * INSERT/UPDATE aborts with a data-truncation error surfacing as a generic 500;
 * on a non-strict server the value is silently truncated to 7 bytes, persisting
 * a corrupt color string the frontend later rejects.
 *
 * This step widens the column to VARCHAR(32) so the longest supported value —
 * an hsla() string such as 'hsla(360, 100%, 100%, 0.5)' (26 chars) — fits.
 */
class Version1008Date20260625130000 extends SimpleMigrationStep {

    /** Target length for crm_note_types.color (fits #rrggbb and hsl()/hsla()). */
    private const COLOR_LENGTH = 32;

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('crm_note_types')) {
            return null;
        }

        $table = $schema->getTable('crm_note_types');
        if ($table->hasColumn('color')) {
            $column = $table->getColumn('color');
            // Only widen — never shrink — so we cannot truncate existing values.
            if ($column->getLength() < self::COLOR_LENGTH) {
                $column->setLength(self::COLOR_LENGTH);
            }
        }

        return $schema;
    }
}
