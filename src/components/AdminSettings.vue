<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-admin-settings">
		<div class="crm-admin-section">
			<h2 class="crm-admin-section-title">
				{{ t('touchpoint', 'Note visibility') }}
			</h2>

			<NcLoadingIcon v-if="settingsStore.loading" :size="32" />
			<NcEmptyContent v-else-if="settingsStore.error"
				:name="t('touchpoint', 'Could not load settings')"
				:description="t('touchpoint', 'Something went wrong while loading. Please try again.')"
			>
				<template #icon><IconAlert :size="48" /></template>
				<template #action>
					<NcButton @click="settingsStore.load()">{{ t('touchpoint', 'Retry') }}</NcButton>
				</template>
			</NcEmptyContent>
			<div v-else class="crm-admin-row">
				<NcCheckboxRadioSwitch
					:model-value="settingsStore.notesPublic"
					type="switch"
					:disabled="settingsStore.saving"
					@update:model-value="saveNotesPublic"
				>
					{{ t('touchpoint', 'Notes are public (all users see all notes)') }}
				</NcCheckboxRadioSwitch>
				<p class="crm-admin-hint">
					{{ t('touchpoint', 'When enabled, every user can read all notes in the system. When disabled, notes are private by default unless explicitly shared.') }}
				</p>
			</div>
		</div>

		<div class="crm-admin-section">
			<h2 class="crm-admin-section-title">
				{{ t('touchpoint', 'Global note types') }}
			</h2>
			<p class="crm-admin-hint crm-admin-hint-standalone">
				{{ t('touchpoint', 'These note types are available to every user on this instance. Users can additionally create their own personal types.') }}
			</p>

			<NcLoadingIcon v-if="globalNoteTypesStore.loading" :size="32" />
			<NcEmptyContent v-else-if="globalNoteTypesStore.error"
				:name="t('touchpoint', 'Could not load global note types.')"
				:description="t('touchpoint', 'Something went wrong while loading. Please try again.')"
			>
				<template #icon><IconAlert :size="48" /></template>
				<template #action>
					<NcButton @click="globalNoteTypesStore.load()">{{ t('touchpoint', 'Retry') }}</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!globalNoteTypesStore.noteTypes.length"
				:name="t('touchpoint', 'No global note types yet.')"
				:description="t('touchpoint', 'These note types are available to every user on this instance.')"
			>
				<template #icon><IconLabel :size="48" /></template>
				<template #action>
					<NcButton ref="addTypeButton" variant="primary" @click="openModal()">
						{{ t('touchpoint', 'Add type') }}
					</NcButton>
				</template>
			</NcEmptyContent>
			<div v-else class="crm-type-list">
				<NoteTypeListItem v-for="(type, index) in globalNoteTypesStore.noteTypes"
					:key="type.id"
					:ref="el => setRowRef(el, index)"
					:type="type"
					:deleting="deletingId === type.id"
					manage-global
					@edit="openModal($event)"
					@delete="onDelete($event, index)"
				/>
			</div>

			<NcButton v-if="globalNoteTypesStore.noteTypes.length"
				ref="addTypeButton"
				class="crm-add-type-button"
				variant="secondary"
				@click="openModal()"
			>
				<template #icon><IconPlus :size="20" /></template>
				{{ t('touchpoint', 'Add type') }}
			</NcButton>
		</div>

		<transition name="crm-fade">
			<div v-if="lastSaved"
				class="crm-admin-saved"
				role="status"
				aria-live="polite"
			>
				<IconCheck :size="16" />
				{{ t('touchpoint', 'Settings saved') }}
			</div>
		</transition>

		<AdminNoteTypeModal v-if="globalNoteTypesStore.showModal"
			:editing-type="globalNoteTypesStore.editingType"
			:saving="globalNoteTypesStore.saving"
			v-model:name-error="nameError"
			@close="closeModal"
			@save="onSaveType"
		/>
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconLabel from 'vue-material-design-icons/Label.vue'
import AdminNoteTypeModal from './AdminNoteTypeModal.vue'
import NoteTypeListItem from './NoteTypeListItem.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useGlobalNoteTypesStore } from '../stores/globalNoteTypes.js'
import { useSettingsStore } from '../stores/settings.js'
import { useNoteTypeDeletion } from '../composables/useNoteTypeDeletion.js'
import { isDuplicateNameError } from '../utils/apiError.js'

const settingsStore = useSettingsStore()
const lastSaved = ref(false)
let savedTimer = null

async function saveNotesPublic(value) {
	const previous = settingsStore.notesPublic
	settingsStore.notesPublic = value
	try {
		await settingsStore.save()
		clearTimeout(savedTimer)
		lastSaved.value = true
		savedTimer = setTimeout(() => { lastSaved.value = false }, 3000)
	} catch {
		showError(t('touchpoint', 'Failed to save settings'))
		settingsStore.notesPublic = previous
	}
}

const globalNoteTypesStore = useGlobalNoteTypesStore()
const confirmDialog = ref(null)
const addTypeButton = ref(null)
const nameError = ref('')

const { deletingId, setRowRef, onDelete } = useNoteTypeDeletion(
	globalNoteTypesStore,
	{ confirmDialog, addTypeButton },
	{
		confirmMessage: t('touchpoint', 'This is a global type: deleting it removes it for every user. This is blocked while any note still uses it.'),
	},
)

// Both stores are always freshly fetched from the API on mount rather than
// trusting the (possibly stale) server-seeded initial-state snapshot
// indefinitely — the seed only covers the moment the page was rendered, not
// a concurrent admin's edits made afterwards.
onMounted(() => {
	settingsStore.load()
	globalNoteTypesStore.load()
})

function openModal(type = null) {
	nameError.value = ''
	globalNoteTypesStore.openModal(type)
}

function closeModal() {
	nameError.value = ''
	globalNoteTypesStore.closeModal()
}

async function onSaveType(payload) {
	nameError.value = ''
	try {
		if (globalNoteTypesStore.editingType) {
			await globalNoteTypesStore.update(globalNoteTypesStore.editingType.id, payload)
		} else {
			await globalNoteTypesStore.create(payload)
		}
		showSuccess(t('touchpoint', 'Note type saved'))
		closeModal()
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
		// 400 from NoteTypeService::mapDuplicateName()) over a generic string.
		showError(message || t('touchpoint', 'Failed to save note type.'))
	}
}
</script>

<style scoped>
.crm-admin-settings {
	max-width: 640px;
}

.crm-admin-section {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);
}

.crm-admin-section-title {
	font-size: var(--default-font-size, 14px);
	font-weight: 600;
	/* NC's own settings-sidebar `.app-navigation-toggle` button floats over the
	top-left of the content area (~34px tall); a zero top margin lets the
	FIRST heading on the page render underneath it, clipping its leading
	text. Core admin-section headings clear it via inherited top margin —
	restore equivalent clearance on this rule, which otherwise overrides
	that default to zero. */
	margin: calc(var(--default-grid-baseline, 4px) * 6) 0 calc(var(--default-grid-baseline, 4px) * 3) 0;
	padding-bottom: calc(var(--default-grid-baseline, 4px) * 2);
	border-bottom: 1px solid var(--color-border);
	color: var(--color-main-text);
}

.crm-admin-row {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 3) 0;
}

.crm-admin-hint {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	/* Align under the switch label using grid-baseline units instead of a magic
	pixel offset (one switch track width + gap). */
	margin: 0;
	margin-inline-start: calc(var(--default-grid-baseline, 4px) * 13);
	line-height: 1.5;
}

/* Standalone hint text (not paired with a switch above it) — no indent. */
.crm-admin-hint-standalone {
	margin-inline-start: 0;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-type-list {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-add-type-button {
	margin-top: calc(var(--default-grid-baseline, 4px) * 1);
}

.crm-admin-saved {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
	font-size: var(--font-size-small, 13px);
	color: var(--color-success);
	padding: calc(var(--default-grid-baseline, 4px) * 1) 0;
}

.crm-fade-enter-active,
.crm-fade-leave-active {
	transition: opacity 0.4s;
}

.crm-fade-enter-from,
.crm-fade-leave-to {
	opacity: 0;
}

/* Honour reduced-motion: show/hide the "Settings saved" status without the
   opacity fade. Matches the spinner/chevron treatment in contacts-integration.js. */
@media (prefers-reduced-motion: reduce) {
	.crm-fade-enter-active,
	.crm-fade-leave-active {
		transition: none;
	}
}
</style>
