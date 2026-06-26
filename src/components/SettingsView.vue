<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-settings-view">
		<h1>{{ t('crm_notes', 'Settings') }}</h1>

		<NcLoadingIcon v-if="settingsStore.loading" :size="32" />
		<NcEmptyContent v-else-if="settingsStore.error && !settingsStore.shareTargets.length"
			:name="t('crm_notes', 'Could not load settings')"
			:description="t('crm_notes', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="settingsStore.load()">{{ t('crm_notes', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<!-- Per-user: default share targets -->
			<section class="crm-settings-section">
				<h2>{{ t('crm_notes', 'Default sharing') }}</h2>
				<p class="crm-settings-hint">
					{{ t('crm_notes', 'New notes are automatically shared with the following users and groups:') }}
				</p>

				<!-- Selected targets list -->
				<div class="crm-share-targets">
					<div v-for="(target, i) in settingsStore.shareTargets"
						:key="`${target.type}-${target.id}`"
						class="crm-share-target-item">
						<span class="crm-share-target-icon">
							<IconGroup v-if="target.type === 'group'" :size="18" />
							<IconAccount v-else :size="18" />
						</span>
						<span class="crm-share-target-name">{{ target.name || target.id }}</span>
						<span class="crm-share-target-type">{{ target.type === 'group' ? t('crm_notes', 'Group') : t('crm_notes', 'User') }}</span>
						<NcCheckboxRadioSwitch :model-value="!!target.canEdit"
							class="crm-share-target-canedit"
							:aria-label="t('crm_notes', 'Allow {name} to edit', { name: target.name || target.id })"
							@update:model-value="setTargetCanEdit(i, $event)">
							{{ t('crm_notes', 'Can edit') }}
						</NcCheckboxRadioSwitch>
						<NcButton type="tertiary"
							:aria-label="t('crm_notes', 'Remove')"
							@click="removeTarget(i)">
							<template #icon><IconClose :size="14" /></template>
						</NcButton>
					</div>
					<p v-if="!settingsStore.shareTargets.length" class="crm-settings-empty">
						{{ t('crm_notes', 'No default sharing configured') }}
					</p>
				</div>

				<!-- Search / add target. NcSelect with async search gives a real,
				     accessible combobox (the ARIA roles, keyboard handling and
				     active-descendant wiring live on the actual focusable input)
				     and matches every other selector in the app. -->
				<div class="crm-share-search">
					<label for="crm-share-search-input" class="crm-group-label">
						{{ t('crm_notes', 'Search users or groups') }}
					</label>
					<NcSelect input-id="crm-share-search-input"
						:model-value="null"
						:options="searchResults"
						:loading="searching"
						:filterable="false"
						:clear-search-on-select="true"
						:aria-label-combobox="t('crm_notes', 'Search users or groups')"
						:placeholder="t('crm_notes', 'Search users or groups…')"
						:no-options-text="searchNoOptionsText"
						label="name"
						track-by="key"
						@search="onSearch"
						@option:selected="addTarget">
						<template #option="option">
							<span class="crm-share-option">
								<IconGroup v-if="option.type === 'group'" :size="16" />
								<IconAccount v-else :size="16" />
								<span class="crm-share-option-name">{{ option.name }}</span>
								<span class="crm-result-type">{{ option.type === 'group' ? t('crm_notes', 'Group') : t('crm_notes', 'User') }}</span>
							</span>
						</template>
					</NcSelect>
				</div>
			</section>

			<div class="crm-settings-actions">
				<NcButton type="primary"
					:disabled="settingsStore.saving || settingsStore.error"
					@click="save">
					{{ settingsStore.saving ? t('crm_notes', 'Saving…') : t('crm_notes', 'Save') }}
				</NcButton>
				<!-- When a load error coexists with already-loaded targets the form
				     still renders, so explain why Save is greyed out instead of
				     leaving it silently disabled (mirrors NoteModal's missing-field
				     hint). Saving over a partially-failed load could clobber real
				     settings, so Save stays disabled until a successful reload. -->
				<p v-if="settingsStore.error && !settingsStore.saving"
					class="crm-save-hint"
					role="status"
					aria-live="polite">
					{{ t('crm_notes', 'Settings could not be loaded fully. Reload before saving to avoid overwriting your configuration.') }}
					<NcButton type="tertiary" @click="settingsStore.load()">
						{{ t('crm_notes', 'Reload') }}
					</NcButton>
				</p>
			</div>
		</template>
	</div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconGroup from 'vue-material-design-icons/AccountGroup.vue'
import IconAccount from 'vue-material-design-icons/Account.vue'
import { useSettingsStore } from '../stores/settings.js'

const settingsStore = useSettingsStore()
// Results currently offered by the NcSelect dropdown, each tagged with a stable
// `key` (type-id) so track-by can dedupe and the already-added filter works.
const searchResults = ref([])
const searching = ref(false)
// Last query the user typed, so the empty-state message can distinguish
// "type to search" from "nothing matched".
const lastQuery = ref('')
let searchTimer = null

// NcSelect shows this when the option list is empty: prompt to type before a
// query exists, and report "no matches" once a non-empty query returned nothing.
const searchNoOptionsText = computed(() =>
	lastQuery.value.trim()
		? t('crm_notes', 'No matching users or groups')
		: t('crm_notes', 'Type to search users or groups'),
)

function setTargetCanEdit(index, value) {
	const target = settingsStore.shareTargets[index]
	if (target) {
		target.canEdit = !!value
	}
}

// Async search driven by NcSelect's @search event (debounced). We disable
// NcSelect's local filtering (:filterable="false") because the principal list
// comes from the server, already matched against the query.
function onSearch(query) {
	clearTimeout(searchTimer)
	lastQuery.value = query
	if (!query.trim()) {
		searchResults.value = []
		searching.value = false
		return
	}
	searching.value = true
	searchTimer = setTimeout(async () => {
		try {
			const results = await settingsStore.searchPrincipals(query.trim())
			// Filter out already-added targets and tag a stable track-by key.
			const existing = new Set(settingsStore.shareTargets.map(target => `${target.type}-${target.id}`))
			searchResults.value = results
				.filter(r => !existing.has(`${r.type}-${r.id}`))
				.map(r => ({ ...r, key: `${r.type}-${r.id}` }))
		} catch {
			searchResults.value = []
		} finally {
			searching.value = false
		}
	}, 300)
}

function addTarget(target) {
	if (!target) return
	const key = `${target.type}-${target.id}`
	if (!settingsStore.shareTargets.some(target_ => `${target_.type}-${target_.id}` === key)) {
		settingsStore.shareTargets.push({
			type: target.type,
			id: target.id,
			name: target.name,
			canEdit: !!target.canEdit,
		})
	}
	// Drop the just-added option so it cannot be picked twice.
	searchResults.value = searchResults.value.filter(r => r.key !== key)
}

function removeTarget(index) {
	settingsStore.shareTargets.splice(index, 1)
}

async function save() {
	try {
		await settingsStore.save()
		showSuccess(t('crm_notes', 'Settings saved'))
	} catch {
		showError(t('crm_notes', 'Failed to save settings'))
	}
}
</script>

<style scoped>
.crm-settings-view {
	padding: calc(var(--default-grid-baseline, 4px) * 6);
	max-width: 600px;
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size rather than the larger user-agent h1 default. */
.crm-settings-view h1 {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 6);
	font-size: calc(var(--default-font-size, 15px) * 1.3);
	font-weight: bold;
}

.crm-settings-section {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 8);
}

/* The single sub-section heading is an <h2> (directly under the page <h1>) so
   the document outline has no skipped level; keep the established section-title
   size rather than the larger user-agent h2 default. */
.crm-settings-section h2 {
	font-size: calc(var(--default-font-size, 15px) * 1.07);
	font-weight: 600;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
	color: var(--color-main-text);
}

.crm-settings-hint {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0;
}

.crm-share-targets {
	margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.crm-share-target-item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 1.5) calc(var(--default-grid-baseline, 4px) * 2);
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.crm-share-target-name {
	flex: 1;
	font-size: var(--default-font-size, 14px);
	/* Clamp long user/group names so the 'Can edit' switch and Remove button
	   stay in view, matching the dropdown option row (.crm-share-option-name). */
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.crm-share-target-type {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
}

.crm-settings-empty {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.crm-share-search {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	margin-top: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-group-label {
	font-weight: 600;
	font-size: var(--default-font-size, 14px);
}

.crm-share-target-canedit {
	margin-right: calc(var(--default-grid-baseline, 4px) * 1);
}

.crm-share-option {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	width: 100%;
}

.crm-share-option-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.crm-result-type {
	margin-left: auto;
	font-size: calc(var(--default-font-size, 14px) - 2px);
	color: var(--color-text-maxcontrast);
}

.crm-settings-actions {
	padding-top: calc(var(--default-grid-baseline, 4px) * 2);
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-save-hint {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
}
</style>
