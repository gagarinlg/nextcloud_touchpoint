<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal size="small" :name="title" @close="noteTypesStore.closeModal()">
		<div class="crm-modal-body">
			<div class="crm-form-row">
				<label for="type-name">
					{{ t('crm_notes', 'Name') }}
					<span class="crm-required" aria-hidden="true">*</span>
				</label>
				<NcTextField input-id="type-name"
					v-model="form.name"
					label-outside
					required
					:placeholder="t('crm_notes', 'Type name')"
					maxlength="128" />
			</div>

			<div class="crm-form-row">
				<label for="type-icon">{{ t('crm_notes', 'Icon') }}</label>
				<NcSelect v-model="form.icon"
					input-id="type-icon"
					:options="iconOptions"
					:aria-label-combobox="t('crm_notes', 'Icon')"
					label="label"
					:reduce="o => o.value" />
			</div>

			<div class="crm-form-row">
				<label for="type-color">{{ t('crm_notes', 'Color') }}</label>
				<NcColorPicker v-model="form.color">
					<NcButton id="type-color"
						class="crm-color-trigger"
						:title="form.color"
						:aria-label="t('crm_notes', 'Choose color')">
						<template #icon>
							<span class="crm-color-swatch" :style="{ background: form.color }" />
						</template>
						{{ t('crm_notes', 'Choose color') }}
					</NcButton>
				</NcColorPicker>
			</div>

			<p v-if="missingFieldsHint"
				class="crm-save-hint"
				role="status"
				aria-live="polite">
				{{ missingFieldsHint }}
			</p>

			<div class="crm-modal-actions">
				<NcButton :disabled="noteTypesStore.saving" @click="noteTypesStore.closeModal()">{{ t('crm_notes', 'Cancel') }}</NcButton>
				<NcButton type="primary"
					:disabled="!canSave || noteTypesStore.saving"
					@click="onSave">
					<template v-if="noteTypesStore.saving" #icon>
						<NcLoadingIcon :size="16" />
					</template>
					{{ noteTypesStore.saving ? t('crm_notes', 'Saving…') : t('crm_notes', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import { useNoteTypesStore } from '../stores/noteTypes.js'

const noteTypesStore = useNoteTypesStore()

const iconOptions = [
	{ label: t('crm_notes', 'Comment'), value: 'icon-comment' },
	{ label: t('crm_notes', 'Phone'), value: 'icon-phone' },
	{ label: t('crm_notes', 'Calendar'), value: 'icon-calendar-dark' },
	{ label: t('crm_notes', 'Mail'), value: 'icon-mail' },
	{ label: t('crm_notes', 'Checkmark'), value: 'icon-checkmark' },
	{ label: t('crm_notes', 'Star'), value: 'icon-star' },
	{ label: t('crm_notes', 'Link'), value: 'icon-link' },
	{ label: t('crm_notes', 'Note'), value: 'icon-category-office' },
]

// Seed the color from the instance's themed primary rather than a hardcoded
// brand-blue literal, so a rethemed instance's default swatch matches its
// palette. Falls back to the classic NC blue if the variable is unavailable
// (e.g. during SSR/tests) or resolves to empty.
function themedDefaultColor() {
	try {
		const value = getComputedStyle(document.documentElement)
			.getPropertyValue('--color-primary-element')
			.trim()
		if (value) return value
	} catch {
		// getComputedStyle unavailable — fall through to the literal default.
	}
	return '#0082c9'
}

const form = ref({ name: '', icon: 'icon-comment', color: themedDefaultColor() })

// Match the disabled guard to onSave()'s validation: a whitespace-only name is
// truthy but fails trim(), which would leave the button enabled yet silently do
// nothing on click.
const canSave = computed(() => !!form.value.name.trim())

// Mirror NoteModal: tell the user which required field is still missing instead
// of leaving Save greyed out with no explanation.
const missingFieldsHint = computed(() => {
	if (canSave.value) return ''
	return t('crm_notes', 'Required: {fields}', { fields: t('crm_notes', 'Name') })
})

const title = computed(() =>
	noteTypesStore.editingType
		? t('crm_notes', 'Edit note type')
		: t('crm_notes', 'Add note type'),
)

onMounted(() => {
	if (noteTypesStore.editingType) {
		const { name, icon, color } = noteTypesStore.editingType
		form.value = { name, icon, color }
	}
})

async function onSave() {
	if (!form.value.name.trim()) return
	try {
		if (noteTypesStore.editingType) {
			await noteTypesStore.update(noteTypesStore.editingType.id, form.value)
		} else {
			await noteTypesStore.create(form.value)
		}
		// Close only after a successful save (NoteModal behaves the same); on
		// error keep the modal open so the user can correct and retry.
		noteTypesStore.closeModal()
	} catch {
		showError(t('crm_notes', 'Failed to save note type.'))
	}
}
</script>

<style scoped>
.crm-modal-body {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
}

.crm-form-row {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
}

.crm-form-row label {
	font-weight: 600;
	font-size: var(--default-font-size, 14px);
}

.crm-required {
	color: var(--color-error);
	margin-inline-start: 2px;
}

.crm-save-hint {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	text-align: end;
}

.crm-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-color-swatch {
	display: inline-block;
	width: 16px;
	height: 16px;
	border-radius: 50%;
	border: 1px solid var(--color-border-dark);
}
</style>
