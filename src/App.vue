<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="crm_notes">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('crm_notes', 'Contacts')"
					:active="activeSection === 'contacts'"
					@click="setSection('contacts')">
					<template #icon><IconContacts :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('crm_notes', 'Note types')"
					:active="activeSection === 'note-types'"
					@click="setSection('note-types')">
					<template #icon><IconLabel :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('crm_notes', 'Settings')"
					:active="activeSection === 'settings'"
					@click="setSection('settings')">
					<template #icon><IconSettings :size="20" /></template>
				</NcAppNavigationItem>
			</template>
		</NcAppNavigation>

		<NcAppContent v-if="activeSection === 'contacts'"
			:show-details="!!contactsStore.currentContact"
			@update:showDetails="onHideDetails">
			<template #list>
				<div class="crm-list-header">
					<NcTextField :model-value="contactsStore.searchQuery"
						type="search"
						:label="t('crm_notes', 'Search contacts')"
						:placeholder="t('crm_notes', 'Search contacts\u2026')"
						:show-trailing-button="false"
						@update:model-value="contactsStore.searchQuery = $event" />
				</div>
				<NcLoadingIcon v-if="contactsStore.loading" :size="32" class="crm-loading" />
				<NcEmptyContent v-else-if="contactsStore.error && !contactsStore.contacts.length"
					:name="t('crm_notes', 'Could not load contacts')"
					:description="t('crm_notes', 'Something went wrong while loading. Please try again.')">
					<template #icon><IconAlert :size="48" /></template>
					<template #action>
						<NcButton @click="contactsStore.load()">{{ t('crm_notes', 'Retry') }}</NcButton>
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="contactsStore.searchQuery && !displayedContacts.length"
					class="crm-contacts-no-results"
					:name="t('crm_notes', 'No contacts found')"
					:description="t('crm_notes', 'No contacts match “{query}”', { query: contactsStore.searchQuery })">
					<template #icon><IconSearch :size="48" /></template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="!displayedContacts.length"
					class="crm-contacts-no-results"
					:name="t('crm_notes', 'No contacts')"
					:description="t('crm_notes', 'Add contacts to your address book to start taking notes.')">
					<template #icon><IconContacts :size="48" /></template>
				</NcEmptyContent>
				<ul v-else class="crm-contacts-list">
					<NcListItem v-for="contact in displayedContacts"
						:key="contact.uid"
						:name="contact.name"
						:active="isSelected(contact)"
						@click="contactsStore.select(contact)">
						<template #icon>
							<ContactAvatar :uid="contact.uid" :name="contact.name" :photo="contact.photo" :is-user="contact.isUser" :size="44" />
						</template>
						<!-- Match the Contacts app's list row subline: the email, or the
						     first phone number when there is no email. -->
						<template #subname>{{ contactSubline(contact) }}</template>
					</NcListItem>
				</ul>
				<p v-if="hiddenContactCount > 0" class="crm-contacts-hint">
					{{ n('crm_notes', '%n more contact — type to search', '%n more contacts — type to search', hiddenContactCount) }}
				</p>
			</template>
			<ContactNotesView v-if="contactsStore.currentContact" />
			<AllNotesView v-else @go-to-contacts="focusContactSearch" />
		</NcAppContent>

		<NcAppContent v-else-if="activeSection === 'note-types'">
			<NoteTypesView />
		</NcAppContent>

		<NcAppContent v-else-if="activeSection === 'settings'">
			<SettingsView />
		</NcAppContent>

		<NcAppContent v-else>
			<AllNotesView @go-to-contacts="setSection('contacts')" />
		</NcAppContent>
	</NcContent>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcContent from '@nextcloud/vue/components/NcContent'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconContacts from 'vue-material-design-icons/AccountMultiple.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconSearch from 'vue-material-design-icons/Magnify.vue'
import IconLabel from 'vue-material-design-icons/Label.vue'
import IconSettings from 'vue-material-design-icons/Cog.vue'
import ContactAvatar from './components/ContactAvatar.vue'
import AllNotesView from './components/AllNotesView.vue'
import ContactNotesView from './components/ContactNotesView.vue'
import NoteTypesView from './components/NoteTypesView.vue'
import SettingsView from './components/SettingsView.vue'
import { useContactsStore } from './stores/contacts.js'
import { useNoteTypesStore } from './stores/noteTypes.js'
import { useSettingsStore } from './stores/settings.js'

const MAX_CONTACTS = 100

const contactsStore = useContactsStore()
const noteTypesStore = useNoteTypesStore()
const settingsStore = useSettingsStore()
const activeSection = ref('contacts')

const displayedContacts = computed(() => contactsStore.filtered.slice(0, MAX_CONTACTS))
const hiddenContactCount = computed(() => Math.max(0, contactsStore.filtered.length - MAX_CONTACTS))

function setSection(section) {
	activeSection.value = section
	if (section !== 'contacts') contactsStore.deselect()
}

function focusContactSearch() {
	activeSection.value = 'contacts'
	contactsStore.deselect()
	nextTick(() => {
		document.querySelector('.crm-list-header input')?.focus()
	})
}

function isSelected(contact) {
	return contactsStore.currentContact?.uid === contact.uid
}

// The Contacts app's list row shows the email as the subline, falling back to
// the first phone number when the contact has no email. Mirror that here so our
// list reads the same way.
function contactSubline(contact) {
	return contact.email || contact.phone || ''
}

function onHideDetails() {
	contactsStore.deselect()
}

onMounted(async () => {
	// Settle each load independently: one store's failure (each surfaces its own
	// error/retry state) must not abort the others, and settingsStore.load()
	// re-throws on failure, so Promise.all would otherwise produce an unhandled
	// rejection here.
	await Promise.allSettled([contactsStore.load(), noteTypesStore.load(), settingsStore.load()])
})
</script>

<style scoped>
.crm-list-header {
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3) calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 13);
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
}

.crm-loading { margin: calc(var(--default-grid-baseline, 4px) * 6) auto; display: block; }

.crm-contacts-list {
	overflow-y: auto;
	padding: 0 calc(var(--default-grid-baseline, 4px) * 1);
	margin: 0;
}

.crm-contacts-hint {
	padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 4) calc(var(--default-grid-baseline, 4px) * 2);
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>
