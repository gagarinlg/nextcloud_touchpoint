<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-note-types-view">
		<div class="crm-view-header">
			<h1>{{ t('touchpoint', 'Note types') }}</h1>
			<NcButton ref="addTypeButton" variant="primary" @click="noteTypesStore.openModal()">
				<template #icon><IconPlus :size="20" /></template>
				{{ t('touchpoint', 'Add type') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="noteTypesStore.loading" :size="32" />
		<NcEmptyContent v-else-if="noteTypesStore.error"
			:name="t('touchpoint', 'Could not load note types')"
			:description="t('touchpoint', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="noteTypesStore.load()">{{ t('touchpoint', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!noteTypesStore.noteTypes.length"
			:name="t('touchpoint', 'No note types')"
			:description="t('touchpoint', 'Create a note type to get started.')">
			<template #icon><IconLabel :size="48" /></template>
			<template #action>
				<NcButton variant="primary" @click="noteTypesStore.openModal()">
					{{ t('touchpoint', 'Add type') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<!-- Global note types (isDefault) show a "Managed by admin" label instead of
			Edit/Delete here — those actions only work in the admin settings page. -->
		<div v-else class="crm-type-list">
			<NoteTypeListItem v-for="(type, index) in noteTypesStore.noteTypes"
				:key="type.id"
				:ref="el => setRowRef(el, index)"
				:type="type"
				:deleting="deletingId === type.id"
				@edit="noteTypesStore.openModal($event)"
				@delete="onDelete($event, index)" />
		</div>

		<NoteTypeModal v-if="noteTypesStore.showModal" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconLabel from 'vue-material-design-icons/Label.vue'
import NoteTypeModal from './NoteTypeModal.vue'
import NoteTypeListItem from './NoteTypeListItem.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useNoteTypesStore } from '../stores/noteTypes.js'
import { useNoteTypeDeletion } from '../composables/useNoteTypeDeletion.js'

const noteTypesStore = useNoteTypesStore()

// Imperative handle to the declarative confirm dialog.
const confirmDialog = ref(null)
const addTypeButton = ref(null)

const { deletingId, setRowRef, onDelete } = useNoteTypeDeletion(
	noteTypesStore,
	{ confirmDialog, addTypeButton },
)
</script>

<style scoped>
.crm-note-types-view {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 640px;
}

.crm-view-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size rather than the larger user-agent h1 default. NC's own
   `.app-navigation-toggle` floats over the top-left of the content area
   (~34px tall) — a zero top margin lets this page's heading render
   underneath it, clipping its leading text, so restore top clearance
   explicitly rather than zeroing it out. */
.crm-view-header h1 {
	margin: calc(var(--default-grid-baseline, 4px) * 6) 0 0 0;
	font-size: calc(var(--default-font-size, 14px) * 1.3);
	font-weight: bold;
}

.crm-type-list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>
