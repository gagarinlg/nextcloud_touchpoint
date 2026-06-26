/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import * as NoteTypeService from '../services/NoteTypeService.js'

export const useNoteTypesStore = defineStore('noteTypes', {
	state: () => ({
		noteTypes: [],
		loading: false,
		error: false,
		// True while a create/update/remove POST/PUT/DELETE is in flight, so the
		// modal's confirm button can disable itself and a rapid double-click can
		// not fire two createNoteType() calls. A UNIQUE(user_id, name) index
		// (defined in the consolidated baseline migration
		// Version1000Date20260626120000) is the server-side guard against
		// duplicate names —
		// the backend now returns a clean 400 on a collision — but disabling the
		// button still avoids a needless second request. Mirrors notes.js save().
		saving: false,
		showModal: false,
		editingType: null,
	}),
	getters: {
		byId: (state) => (id) => state.noteTypes.find(t => t.id === id),
	},
	actions: {
		async load() {
			this.loading = true
			this.error = false
			try {
				this.noteTypes = await NoteTypeService.getAllNoteTypes()
			} catch (e) {
				this.error = true
				throw e
			} finally {
				this.loading = false
			}
		},
		async create(payload) {
			// Guard against re-entrant saves: a double-click must not POST twice
			// and create two identical types.
			if (this.saving) return
			this.saving = true
			try {
				await NoteTypeService.createNoteType(payload)
				await this.load()
			} finally {
				this.saving = false
			}
		},
		async update(id, payload) {
			if (this.saving) return
			this.saving = true
			try {
				await NoteTypeService.updateNoteType(id, payload)
				await this.load()
			} finally {
				this.saving = false
			}
		},
		async usage(id) {
			const { count } = await NoteTypeService.getNoteTypeUsage(id)
			return count
		},
		async remove(id) {
			if (this.saving) return
			this.saving = true
			try {
				await NoteTypeService.deleteNoteType(id)
				await this.load()
			} finally {
				this.saving = false
			}
		},
		openModal(type = null) {
			this.editingType = type
			this.showModal = true
		},
		closeModal() {
			this.showModal = false
			this.editingType = null
		},
	},
})
