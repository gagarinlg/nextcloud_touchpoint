/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import * as ContactService from '../services/ContactService.js'

export const useContactsStore = defineStore('contacts', {
	state: () => ({
		contacts: [],
		currentContact: null,
		searchQuery: '',
		loading: false,
		error: false,
	}),
	getters: {
		filtered: (state) => {
			const q = state.searchQuery.toLowerCase()
			if (!q) return state.contacts
			// Coerce to string defensively: the API should always send strings,
			// but a non-string field must never throw here (which would crash the
			// whole list render as the user types). Match the same fields the
			// Contacts app exposes on a row — name, email, organisation, phone.
			return state.contacts.filter(c =>
				String(c.name ?? '').toLowerCase().includes(q)
				|| String(c.email ?? '').toLowerCase().includes(q)
				|| String(c.org ?? '').toLowerCase().includes(q)
				|| String(c.phone ?? '').toLowerCase().includes(q),
			)
		},
		byUid: (state) => (uid) => state.contacts.find(c => c.uid === uid),
	},
	actions: {
		async load(term = '') {
			this.loading = true
			this.error = false
			try {
				this.contacts = await ContactService.searchContacts(term)
			} catch (e) {
				this.error = true
				throw e
			} finally {
				this.loading = false
			}
		},
		select(contact) {
			this.currentContact = contact
		},
		deselect() {
			this.currentContact = null
		},
	},
})
