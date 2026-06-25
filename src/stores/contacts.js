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
			return state.contacts.filter(c =>
				c.name.toLowerCase().includes(q)
				|| (c.email || '').toLowerCase().includes(q),
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
