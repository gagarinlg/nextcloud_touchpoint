/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Stable, machine-readable error code for a duplicate note-type name
 * rejection (400), set by NoteTypeService::mapDuplicateName() and surfaced by
 * ErrorHandler as `code` alongside the translated `message`. See
 * docs/API.md's "Error handling" section: callers must branch on this HTTP
 * status + code pair, never on the (locale-dependent, translated) `message`
 * text itself.
 */
export const DUPLICATE_NAME_CODE = 'duplicate_name'

/**
 * True when an axios error response is the duplicate-name 400 rejection from
 * POST/PUT /api/note-types (or /api/admin/note-types).
 *
 * @param {object} error the caught axios error
 * @return {boolean}
 */
export function isDuplicateNameError(error) {
	return error?.response?.status === 400 && error?.response?.data?.code === DUPLICATE_NAME_CODE
}
