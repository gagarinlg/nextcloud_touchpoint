/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { ref, nextTick } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'

/**
 * Shared delete-flow orchestration for a note-type list row: proactive usage
 * lookup, confirm dialog, per-row re-entrancy guard, and focus recovery after
 * the row is removed from the DOM. Used by both NoteTypesView.vue (personal
 * note types) and AdminSettings.vue (global note types) — the two call sites
 * differ only in which Pinia store backs them and (optionally) the wording of
 * the confirm-dialog body text. The confirm-dialog title and the in-use error
 * always name the actual type (`type.name`) so a user managing several types
 * can tell which one a dialog/error refers to — this is built fresh on every
 * onDelete() call, not once at setup time, since `type` is only known per-call.
 *
 * @param {object} store a Pinia note-type store exposing usage(id)/remove(id)/noteTypes
 * @param {object} refs imperative refs owned by the calling component
 * @param {import('vue').Ref} refs.confirmDialog ConfirmDialog component ref
 * @param {import('vue').Ref} refs.addTypeButton "Add type" NcButton ref, focused when the list becomes empty
 * @param {object} [opts]
 * @param {string} [opts.confirmMessage] confirm-dialog body text (static;
 *   defaults to a generic "blocked while in use" caveat)
 * @return {{deletingId: import('vue').Ref, rowRefs: import('vue').Ref, setRowRef: Function, onDelete: Function}}
 */
export function useNoteTypeDeletion(store, { confirmDialog, addTypeButton }, opts = {}) {
	const confirmMessage = opts.confirmMessage
		?? t('touchpoint', 'This is blocked while any note still uses it.')

	// Tracks which type's delete is currently in flight, so a rapid double-click
	// on the same row's Delete button no-ops instead of firing two concurrent
	// DELETE requests. Scoped per-row (not the store's shared `saving` flag) so
	// one row's in-flight delete doesn't disable every other row's buttons.
	const deletingId = ref(null)

	// Template refs to each row (by index), used to move focus to a sensible
	// target after a delete removes the previously-focused row from the DOM.
	const rowRefs = ref([])
	function setRowRef(el, index) {
		if (el) rowRefs.value[index] = el
		else delete rowRefs.value[index]
	}

	async function onDelete(type, index) {
		// Look up how many notes still use this type so we can warn about (or
		// block) a delete that would otherwise orphan note_type_id.
		let inUse = 0
		try {
			inUse = await store.usage(type.id)
		} catch {
			// Usage lookup failed — fall back to a plain confirm; the server still
			// enforces the in-use guard and returns 409.
		}

		if (inUse > 0) {
			// The server blocks deleting a type that is still in use (409). Explain
			// why instead of offering a delete that is guaranteed to fail. Name the
			// type so an admin/user managing several types can tell which one this
			// refers to.
			showError(n('touchpoint',
				'"{name}" is used by %n note. Reassign or delete it first.',
				'"{name}" is used by %n notes. Reassign or delete those notes first.',
				inUse,
				{ name: type.name }))
			return
		}

		const ok = await confirmDialog.value?.show({
			message: confirmMessage,
			name: t('touchpoint', 'Delete note type "{name}"?', { name: type.name }),
			confirmLabel: t('touchpoint', 'Delete'),
		})
		if (!ok) return
		if (deletingId.value === type.id) return
		deletingId.value = type.id
		try {
			await store.remove(type.id)
			showSuccess(t('touchpoint', 'Note type deleted'))
			await nextTick()
			focusAfterDelete(index)
		} catch (e) {
			if (e?.response?.status === 409) {
				showError(t('touchpoint', 'This note type is still used by existing notes.'))
			} else {
				showError(t('touchpoint', 'Failed to delete note type.'))
			}
		} finally {
			deletingId.value = null
		}
	}

	// After a row is removed from the DOM, focus falls back to <body> since the
	// button that had focus is gone — move it to the next remaining row (same
	// index, since the list shifted up), the previous row if that was the last
	// one, or the "Add type" button if the list is now empty.
	function focusAfterDelete(removedIndex) {
		rowRefs.value = rowRefs.value.filter(Boolean)
		const remaining = store.noteTypes.length
		if (!remaining) {
			addTypeButton.value?.$el?.focus()
			return
		}
		const targetIndex = Math.min(removedIndex, remaining - 1)
		const target = rowRefs.value[targetIndex]
		target?.$el?.querySelector('button')?.focus()
	}

	return { deletingId, rowRefs, setRowRef, onDelete }
}
