<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-admin-settings">
		<div class="crm-admin-section">
			<h2 class="crm-admin-section-title">
				{{ t('crm_notes', 'Note visibility') }}
			</h2>

			<div class="crm-admin-row">
				<NcCheckboxRadioSwitch
					v-model="notesPublic"
					type="switch"
					:disabled="saving"
					@update:model-value="save">
					{{ t('crm_notes', 'Notes are public (all users see all notes)') }}
				</NcCheckboxRadioSwitch>
				<p class="crm-admin-hint">
					{{ t('crm_notes', 'When enabled, every user can read all notes in the system. When disabled, notes are private by default unless explicitly shared.') }}
				</p>
			</div>
		</div>

		<transition name="crm-fade">
			<div v-if="lastSaved"
				class="crm-admin-saved"
				role="status"
				aria-live="polite">
				<IconCheck :size="16" />
				{{ t('crm_notes', 'Settings saved') }}
			</div>
		</transition>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { showError } from '@nextcloud/dialogs'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import IconCheck from 'vue-material-design-icons/Check.vue'
import { saveSettings } from '../services/SettingsService.js'

const notesPublic = ref(loadState('crm_notes', 'notesPublic', false))
const saving = ref(false)
const lastSaved = ref(false)
let savedTimer = null

async function save(value) {
	saving.value = true
	try {
		await saveSettings({ notesPublic: value })
		clearTimeout(savedTimer)
		lastSaved.value = true
		savedTimer = setTimeout(() => { lastSaved.value = false }, 3000)
	} catch {
		showError(t('crm_notes', 'Failed to save settings'))
		notesPublic.value = !value
	} finally {
		saving.value = false
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
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 3) 0;
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
