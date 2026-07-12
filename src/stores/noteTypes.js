/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import * as NoteTypeService from '../services/NoteTypeService.js'
import { createNoteTypeCrudStore } from './createNoteTypeCrudStore.js'

export const useNoteTypesStore = createNoteTypeCrudStore('noteTypes', {
	getAll: NoteTypeService.getAllNoteTypes,
	usage: NoteTypeService.getNoteTypeUsage,
	create: NoteTypeService.createNoteType,
	update: NoteTypeService.updateNoteType,
	remove: NoteTypeService.deleteNoteType,
})
