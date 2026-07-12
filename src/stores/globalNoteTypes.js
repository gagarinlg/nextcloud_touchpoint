/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { loadState } from '@nextcloud/initial-state'
import * as AdminNoteTypeService from '../services/AdminNoteTypeService.js'
import { createNoteTypeCrudStore } from './createNoteTypeCrudStore.js'

/**
 * Admin-scoped note-type CRUD store for the "Global note types" admin
 * settings section — same CRUD shape as stores/noteTypes.js (via the shared
 * createNoteTypeCrudStore() factory), backed by AdminNoteTypeService.js
 * instead, and seeded from the admin form's server-rendered initial state
 * (Admin.php::getForm()) so the list isn't empty before the first load()
 * (called on mount) refreshes it from the API.
 */
export const useGlobalNoteTypesStore = createNoteTypeCrudStore('globalNoteTypes', {
	getAll: AdminNoteTypeService.getAllGlobalNoteTypes,
	usage: AdminNoteTypeService.getGlobalNoteTypeUsage,
	create: AdminNoteTypeService.createGlobalNoteType,
	update: AdminNoteTypeService.updateGlobalNoteType,
	remove: AdminNoteTypeService.deleteGlobalNoteType,
}, {
	initialNoteTypes: () => loadState('touchpoint', 'globalNoteTypes', []),
})
