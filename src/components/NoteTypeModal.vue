<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NoteTypeFormModal scope="personal"
		:editing-type="noteTypesStore.editingType"
		:saving="noteTypesStore.saving"
		v-model:name-error="nameError"
		@close="onClose"
		@save="onSave"
	/>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NoteTypeFormModal from './NoteTypeFormModal.vue'
import { useNoteTypesStore } from '../stores/noteTypes.js'
import { isDuplicateNameError } from '../utils/apiError.js'

const noteTypesStore = useNoteTypesStore()
const nameError = ref('')

function onClose() {
	nameError.value = ''
	noteTypesStore.closeModal()
}

async function onSave(payload) {
	nameError.value = ''
	try {
		if (noteTypesStore.editingType) {
			await noteTypesStore.update(noteTypesStore.editingType.id, payload)
		} else {
			await noteTypesStore.create(payload)
		}
		showSuccess(t('touchpoint', 'Note type saved'))
		// Close only after a successful save; on error keep the modal open so the
		// user can correct and retry.
		noteTypesStore.closeModal()
	} catch (e) {
		const message = e?.response?.data?.message
		// The duplicate-name rejection names exactly which field is wrong, so it
		// is shown inline under the Name input (in addition to the toast) rather
		// than only as a toast disconnected from the still-open field. Branch on
		// the stable `code` field, not the translated `message` text (see
		// docs/API.md's Error handling section).
		if (isDuplicateNameError(e)) {
			nameError.value = message
		}
		// Prefer the server's specific, actionable message (e.g. duplicate-name
		// 400 from NoteTypeService::mapDuplicateName()) over a generic string,
		// so the user knows what to fix rather than just that something failed.
		showError(message || t('touchpoint', 'Failed to save note type.'))
	}
}
</script>
