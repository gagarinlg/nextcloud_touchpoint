/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import * as SettingsService from '../services/SettingsService.js'

export const useSettingsStore = defineStore('settings', {
	state: () => ({
		notesPublic: false,
		isAdmin: false,
		shareTargets: [],
		loading: false,
		saving: false,
		// True after load() failed, so the view can show an error/retry state and
		// disable Save — a failed fetch must not look like a genuinely-empty config
		// that the user could then overwrite their real settings with.
		error: false,
	}),
	actions: {
		async load() {
			this.loading = true
			this.error = false
			try {
				const data = await SettingsService.getSettings()
				this.notesPublic = data.notesPublic
				this.isAdmin = data.isAdmin
				this.shareTargets = data.shareTargets ?? []
			} catch (e) {
				this.error = true
				throw e
			} finally {
				this.loading = false
			}
		},
		async save() {
			this.saving = true
			try {
				const data = await SettingsService.saveSettings({
					notesPublic: this.notesPublic,
					shareTargets: this.shareTargets,
				})
				this.notesPublic = data.notesPublic
				this.isAdmin = data.isAdmin
				this.shareTargets = data.shareTargets ?? []
			} finally {
				this.saving = false
			}
		},
		async searchPrincipals(q) {
			if (!q) return []
			return SettingsService.searchPrincipals(q)
		},
	},
})
