/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'

/**
 * Shared Pinia store factory for a note-type CRUD surface. Both
 * stores/noteTypes.js (personal note types) and stores/globalNoteTypes.js
 * (admin-managed global note types) have an identical state shape and action
 * set — list/loading/error/saving/showModal/editingType, plus the
 * `if (this.saving) return` re-entrancy guard on create/update/remove — and
 * previously kept two byte-for-byte copies of it. The only genuine
 * differences are which service module backs the HTTP calls and (for the
 * global store) the initial-state seed for `noteTypes`.
 *
 * @param {string} storeId Pinia store id (must be unique across the app)
 * @param {object} service a service module exposing getAll/usage/create/update/remove-style functions
 * @param {Function} service.getAll () => Promise<Array>
 * @param {Function} service.usage (id) => Promise<{count: number}>
 * @param {Function} service.create (payload) => Promise
 * @param {Function} service.update (id, payload) => Promise
 * @param {Function} service.remove (id) => Promise
 * @param {object} [opts]
 * @param {Function} [opts.initialNoteTypes] () => Array, seed for the `noteTypes` state
 *   (e.g. reading a server-rendered initial-state snapshot); defaults to an empty array
 * @return {Function} a Pinia `useStore()` composable
 */
export function createNoteTypeCrudStore(storeId, service, opts = {}) {
	const initialNoteTypes = opts.initialNoteTypes ?? (() => [])

	return defineStore(storeId, {
		state: () => ({
			noteTypes: initialNoteTypes(),
			loading: false,
			error: false,
			// True while a create/update/remove POST/PUT/DELETE is in flight, so the
			// modal's confirm button can disable itself and a rapid double-click can
			// not fire two createNoteType() calls. A UNIQUE(user_id, name) index
			// (defined in the consolidated baseline migration
			// Version1000Date20260627000000) is the server-side guard against
			// duplicate names — the backend now returns a clean 400 on a collision —
			// but disabling the button still avoids a needless second request.
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
					this.noteTypes = await service.getAll()
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
					await service.create(payload)
					await this.load()
				} finally {
					this.saving = false
				}
			},
			async update(id, payload) {
				if (this.saving) return
				this.saving = true
				try {
					await service.update(id, payload)
					await this.load()
				} finally {
					this.saving = false
				}
			},
			async usage(id) {
				const { count } = await service.usage(id)
				return count
			},
			async remove(id) {
				if (this.saving) return
				this.saving = true
				try {
					await service.remove(id)
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
}
