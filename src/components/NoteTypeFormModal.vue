<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal size="small"
		:name="title"
		close-on-click-outside
		@close="$emit('close')"
	>
		<div class="crm-modal-body" @keydown.esc="$emit('close')">
			<div class="crm-form-row">
				<label :for="nameInputId">
					{{ t('touchpoint', 'Name') }}
					<span class="crm-required" aria-hidden="true">*</span>
				</label>
				<NcTextField :id="nameInputId"
					v-model="form.name"
					label-outside
					required
					:placeholder="t('touchpoint', 'Type name')"
					maxlength="128"
					:aria-describedby="nameError ? nameErrorId : undefined"
					@update:model-value="nameError = ''"
				/>
				<p v-if="nameError"
					:id="nameErrorId"
					class="crm-field-error"
					role="alert"
				>
					{{ nameError }}
				</p>
			</div>

			<div class="crm-form-row">
				<label :for="iconInputId">{{ t('touchpoint', 'Icon') }}</label>
				<NcSelect v-model="form.icon"
					:input-id="iconInputId"
					:options="iconOptions"
					:aria-label-combobox="t('touchpoint', 'Icon')"
					label="label"
					:reduce="o => o.value"
				>
					<template #option="option">
						<component :is="iconComponentForType(option.value)"
							v-if="iconComponentForType(option.value)"
							:size="16"
							class="crm-icon-option-glyph"
							aria-hidden="true"
						/>
						{{ option.label }}
					</template>
					<template #selected-option="option">
						<component :is="iconComponentForType(option.value)"
							v-if="iconComponentForType(option.value)"
							:size="16"
							class="crm-icon-option-glyph"
							aria-hidden="true"
						/>
						{{ option.label }}
					</template>
				</NcSelect>
			</div>

			<div class="crm-form-row">
				<!-- A non-labelling caption (a plain styled span, like the files
					group in NoteModal): the control here is an NcButton whose own
					visible text "Choose color" is its accessible name. A <label
					for> would not override a button's name, so associating one
					would make the visible caption ("Color") and the programmatic
					name ("Choose color") diverge (WCAG 2.5.3 Label in Name). -->
				<span class="crm-group-label">{{ t('touchpoint', 'Color') }}</span>
				<NcColorPicker v-model="form.color">
					<NcButton class="crm-color-trigger"
						:title="form.color"
					>
						<template #icon>
							<span class="crm-color-swatch" :style="{ background: form.color }" />
						</template>
						{{ t('touchpoint', 'Choose color') }}
					</NcButton>
				</NcColorPicker>
			</div>

			<div class="crm-form-row">
				<span class="crm-group-label">{{ t('touchpoint', 'Preview') }}</span>
				<NoteTypeBadge :type="previewType" />
			</div>

			<p v-if="missingFieldsHint"
				class="crm-save-hint"
				role="status"
				aria-live="polite"
			>
				{{ missingFieldsHint }}
			</p>

			<!-- Legend explaining the red asterisk that marks required fields above. -->
			<p class="crm-required-legend">
				<span class="crm-required" aria-hidden="true">*</span>
				{{ t('touchpoint', 'required') }}
			</p>

			<div class="crm-modal-actions">
				<NcButton :disabled="saving" @click="$emit('close')">{{ t('touchpoint', 'Cancel') }}</NcButton>
				<NcButton variant="primary"
					:disabled="!canSave || saving"
					@click="onSave"
				>
					<template v-if="saving" #icon>
						<NcLoadingIcon :size="16" />
					</template>
					{{ saving ? t('touchpoint', 'Saving…') : t('touchpoint', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcColorPicker from '@nextcloud/vue/components/NcColorPicker'
import NoteTypeBadge from './NoteTypeBadge.vue'
import { iconOptions as buildIconOptions, iconComponentForType, themedDefaultColor } from '../utils/noteTypeIcon.js'

const props = defineProps({
	editingType: { type: Object, default: null },
	saving: { type: Boolean, default: false },
	// Distinguishes the personal-type modal from the admin/global-type modal
	// so the two thin wrappers (NoteTypeModal.vue, AdminNoteTypeModal.vue) can
	// share this one form/template/style while still showing the right title
	// and DOM ids (NcModal renders both in the same document at different
	// times, but ids must stay unique in case any lingering markup overlaps).
	scope: { type: String, default: 'personal', validator: v => ['personal', 'global'].includes(v) },
	// A field-adjacent validation message (e.g. the server's duplicate-name
	// rejection) rendered directly under the Name input, in addition to
	// whatever toast the caller also shows — a floating toast alone is not
	// adjacent to the field the user needs to correct (web UX checklist #13).
	nameError: { type: String, default: '' },
})

const emit = defineEmits(['close', 'save', 'update:nameError'])

const nameInputId = computed(() => props.scope === 'global' ? 'admin-type-name' : 'type-name')
const iconInputId = computed(() => props.scope === 'global' ? 'admin-type-icon' : 'type-icon')
const nameErrorId = computed(() => `${nameInputId.value}-error`)

// Local proxy so editing the field clears the error without waiting for a
// round-trip through the parent's v-model.
const nameError = computed({
	get: () => props.nameError,
	set: (value) => emit('update:nameError', value),
})

// Only surface a legacy icon option when the type being edited already
// carries it — never as a choice for a brand-new type (see iconOptions()'s
// own docblock in utils/noteTypeIcon.js).
const iconOptions = buildIconOptions({
	includeLegacyNote: props.editingType?.icon === 'icon-note',
	includeLegacyCalendar: props.editingType?.icon === 'icon-calendar',
})

function initialForm() {
	if (props.editingType) {
		const { name, icon, color } = props.editingType
		return { name, icon, color }
	}
	return { name: '', icon: 'icon-comment', color: themedDefaultColor() }
}

const form = ref(initialForm())

// Live preview reflecting the in-progress name/icon/color combination, so the
// user sees the final rendered badge before committing (NoteTypeBadge itself
// renders nothing for a blank name, so a placeholder name is substituted here
// only for the preview).
const previewType = computed(() => ({
	name: form.value.name.trim() || t('touchpoint', 'Type name'),
	icon: form.value.icon,
	color: form.value.color,
}))

// Match the disabled guard to onSave()'s validation: a whitespace-only name is
// truthy but fails trim(), which would leave the button enabled yet silently do
// nothing on click.
const canSave = computed(() => !!form.value.name.trim())

const missingFieldsHint = computed(() => {
	if (canSave.value) return ''
	return t('touchpoint', 'Required: {fields}', { fields: t('touchpoint', 'Name') })
})

const title = computed(() => {
	if (props.scope === 'global') {
		return props.editingType
			? t('touchpoint', 'Edit global note type')
			: t('touchpoint', 'Add global note type')
	}
	return props.editingType
		? t('touchpoint', 'Edit note type')
		: t('touchpoint', 'Add note type')
})

function onSave() {
	if (!form.value.name.trim()) return
	emit('save', { ...form.value })
}
</script>

<style scoped>
/* NcModal's own `.modal-header` is `position: absolute; top: 0` inside a
   viewport-covering mask, independent of NC's fixed top header/unified-search
   bar (also `top: 0`, same `--header-height`) — both occupy the identical
   y:0-50 strip while the modal is open, so the modal title renders
   superimposed on the search placeholder underneath it. Push the modal
   header down by the same `--header-height` NC's own top bar uses, so it
   renders below that bar instead of on top of it. */
:deep(.modal-header) {
	top: var(--header-height, 50px) !important;
}

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

.crm-form-row label,
.crm-form-row .crm-group-label {
	font-weight: 600;
	font-size: var(--default-font-size, 14px);
}

.crm-required {
	color: var(--color-error);
	margin-inline-start: 2px;
}

.crm-field-error {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-error);
}

.crm-save-hint {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	text-align: end;
}

.crm-required-legend {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
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

.crm-icon-option-glyph {
	margin-inline-end: calc(var(--default-grid-baseline, 4px) * 1.5);
	flex-shrink: 0;
}
</style>
