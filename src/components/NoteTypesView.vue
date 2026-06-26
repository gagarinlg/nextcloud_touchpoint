<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-note-types-view">
		<div class="crm-view-header">
			<h1>{{ t('crm_notes', 'Note types') }}</h1>
			<NcButton type="primary" @click="noteTypesStore.openModal()">
				<template #icon><IconPlus :size="20" /></template>
				{{ t('crm_notes', 'Add type') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="noteTypesStore.loading" :size="32" />
		<NcEmptyContent v-else-if="noteTypesStore.error"
			:name="t('crm_notes', 'Could not load note types')"
			:description="t('crm_notes', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="noteTypesStore.load()">{{ t('crm_notes', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!noteTypesStore.noteTypes.length"
			:name="t('crm_notes', 'No note types')"
			:description="t('crm_notes', 'Create a note type to get started.')">
			<template #icon><IconLabel :size="48" /></template>
			<template #action>
				<NcButton type="primary" @click="noteTypesStore.openModal()">
					{{ t('crm_notes', 'Add type') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<div v-else class="crm-type-list">
			<div v-for="type in noteTypesStore.noteTypes" :key="type.id" class="crm-type-item">
				<NoteTypeBadge :type="type" />
				<div class="crm-type-actions">
					<NcButton type="tertiary"
						:aria-label="t('crm_notes', 'Edit type')"
						@click="noteTypesStore.openModal(type)">
						<template #icon><IconPencil :size="16" /></template>
					</NcButton>
					<NcButton type="tertiary"
						:aria-label="t('crm_notes', 'Delete type')"
						@click="onDelete(type)">
						<template #icon><IconDelete :size="16" /></template>
					</NcButton>
				</div>
			</div>
		</div>

		<NoteTypeModal v-if="noteTypesStore.showModal" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconLabel from 'vue-material-design-icons/Label.vue'
import NoteTypeModal from './NoteTypeModal.vue'
import NoteTypeBadge from './NoteTypeBadge.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useNoteTypesStore } from '../stores/noteTypes.js'

const noteTypesStore = useNoteTypesStore()

// Imperative handle to the declarative confirm dialog.
const confirmDialog = ref(null)

async function onDelete(type) {
	// Look up how many of the user's notes still use this type so we can warn
	// about (or block) a delete that would otherwise orphan note_type_id.
	let inUse = 0
	try {
		inUse = await noteTypesStore.usage(type.id)
	} catch {
		// Usage lookup failed — fall back to a plain confirm; the server still
		// enforces the in-use guard and returns 409.
	}

	if (inUse > 0) {
		// The server blocks deleting a type that is still in use (409). Explain
		// why instead of offering a delete that is guaranteed to fail.
		showError(n('crm_notes',
			'This type is used by %n note. Reassign or delete it first.',
			'This type is used by %n notes. Reassign or delete those notes first.',
			inUse))
		return
	}

	const ok = await confirmDialog.value?.show({
		message: t('crm_notes', 'Delete this note type? Notes using it will lose their type.'),
		name: t('crm_notes', 'Delete note type'),
		confirmLabel: t('crm_notes', 'Delete'),
	})
	if (!ok) return
	try {
		await noteTypesStore.remove(type.id)
	} catch (e) {
		if (e?.response?.status === 409) {
			showError(t('crm_notes', 'This note type is still used by existing notes.'))
		} else {
			showError(t('crm_notes', 'Failed to delete note type.'))
		}
	}
}
</script>

<style scoped>
.crm-note-types-view {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 680px;
}

.crm-view-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size rather than the larger user-agent h1 default. */
.crm-view-header h1 {
	margin: 0;
	font-size: calc(var(--default-font-size, 14px) * 1.3);
	font-weight: bold;
}

.crm-type-list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-type-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: calc(var(--default-grid-baseline, 4px) * 2.5) calc(var(--default-grid-baseline, 4px) * 3.5);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.crm-type-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}
</style>
