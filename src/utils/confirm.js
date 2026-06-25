/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * Show a themed, translatable, focus-trapped confirmation dialog with a
 * destructive primary button, replacing the native window.confirm().
 *
 * @param {string} text the question/body shown to the user
 * @param {string} [name] the dialog title
 * @param {string} [confirmLabel] label for the destructive confirm button
 * @return {Promise<boolean>} resolves true when the user confirms
 */
export async function confirmDestructive(text, name, confirmLabel) {
	const { getDialogBuilder } = await import('@nextcloud/dialogs')

	return new Promise((resolve) => {
		let settled = false
		const settle = (value) => {
			if (!settled) {
				settled = true
				resolve(value)
			}
		}

		getDialogBuilder(name || t('crm_notes', 'Confirm'))
			.setText(text)
			.addButton({
				label: t('crm_notes', 'Cancel'),
				type: 'tertiary',
				callback: () => settle(false),
			})
			.addButton({
				label: confirmLabel || t('crm_notes', 'Delete'),
				type: 'error',
				callback: () => settle(true),
			})
			.build()
			.show()
			.then(() => settle(false))
			.catch(() => settle(false))
	})
}
