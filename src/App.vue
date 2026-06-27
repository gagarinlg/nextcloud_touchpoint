<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcContent app-name="touchpoint">
		<NcAppNavigation>
			<template #list>
				<NcAppNavigationItem :name="t('touchpoint', 'Notes')"
					:active="activeSection === 'contacts'"
					@click="setSection('contacts')">
					<template #icon><IconNotes :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('touchpoint', 'Note types')"
					:active="activeSection === 'note-types'"
					@click="setSection('note-types')">
					<template #icon><IconLabel :size="20" /></template>
				</NcAppNavigationItem>
				<NcAppNavigationItem :name="t('touchpoint', 'Settings')"
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
						:label="t('touchpoint', 'Search contacts')"
						:placeholder="t('touchpoint', 'Search contacts\u2026')"
						:trailing-button-label="t('touchpoint', 'Clear search')"
						@trailing-button-click="contactsStore.searchQuery = ''"
						@update:model-value="contactsStore.searchQuery = $event" />
				</div>
				<NcLoadingIcon v-if="contactsStore.loading" :size="32" class="crm-loading" />
				<NcEmptyContent v-else-if="contactsStore.error && !contactsStore.contacts.length"
					:name="t('touchpoint', 'Could not load contacts')"
					:description="t('touchpoint', 'Something went wrong while loading. Please try again.')">
					<template #icon><IconAlert :size="48" /></template>
					<template #action>
						<NcButton @click="contactsStore.load()">{{ t('touchpoint', 'Retry') }}</NcButton>
					</template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="contactsStore.searchQuery && !displayedContacts.length"
					class="crm-contacts-no-results"
					:name="t('touchpoint', 'No contacts found')"
					:description="t('touchpoint', 'No contacts match “{query}”', { query: contactsStore.searchQuery })">
					<template #icon><IconSearch :size="48" /></template>
				</NcEmptyContent>
				<NcEmptyContent v-else-if="!displayedContacts.length"
					class="crm-contacts-no-results"
					:name="t('touchpoint', 'No contacts')"
					:description="t('touchpoint', 'Add contacts to your address book to start taking notes.')">
					<template #icon><IconContacts :size="48" /></template>
				</NcEmptyContent>
				<ul v-else class="crm-contacts-list">
					<NcListItem v-for="contact in displayedContacts"
						:key="contact.uid"
						class="crm-contact-row"
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
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
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
import IconNotes from 'vue-material-design-icons/NoteMultipleOutline.vue'
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

const contactsStore = useContactsStore()
const noteTypesStore = useNoteTypesStore()
const settingsStore = useSettingsStore()
const activeSection = ref('contacts')

// Mirror the Contacts app: show the entire (client-side filtered) address book,
// not a truncated window. The list virtualises its paint via CSS
// content-visibility so even a few thousand rows scroll smoothly.
const displayedContacts = computed(() => contactsStore.filtered)

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

// Deep link from the Contacts tab: /apps/touchpoint#contact/<encoded-uid>.
// Parse the fragment and return the decoded UID, or null when absent/malformed.
function contactUidFromHash() {
	const match = window.location.hash.match(/^#contact\/(.+)$/)
	if (!match) return null
	try {
		return decodeURIComponent(match[1])
	} catch {
		// Malformed percent-encoding — treat as no deep link rather than throwing.
		return null
	}
}

// Apply the #contact/<uid> deep link: switch to the contacts section and select
// the contact once it is loaded. Called on startup (after contacts load) and on
// every hashchange so re-clicking the Contacts-tab link while the app is already
// open re-navigates instead of silently doing nothing.
function applyContactDeepLink() {
	const uid = contactUidFromHash()
	if (!uid) return
	activeSection.value = 'contacts'
	if (!contactsStore.selectByUid(uid)) {
		// Contacts may not have finished loading yet (startup race). The onMounted
		// caller awaits the load before invoking us, but a hashchange can fire
		// mid-load; in that case the post-load call below will pick it up.
		contactsStore.deselect()
	}
}

function onHashChange() {
	applyContactDeepLink()
}

onMounted(async () => {
	window.addEventListener('hashchange', onHashChange)
	// Settle each load independently: one store's failure (each surfaces its own
	// error/retry state) must not abort the others, and settingsStore.load()
	// re-throws on failure, so Promise.all would otherwise produce an unhandled
	// rejection here.
	await Promise.allSettled([contactsStore.load(), noteTypesStore.load(), settingsStore.load()])
	// Now that contacts are loaded, honour any #contact/<uid> deep link the user
	// arrived with (e.g. the "Open in Touchpoint" link on the Contacts tab).
	applyContactDeepLink()
})

onBeforeUnmount(() => {
	window.removeEventListener('hashchange', onHashChange)
})
</script>

<style scoped>
/* NcAppContent renders our #list slot into the splitpanes "list" pane, which has
   a bounded height but overflow:hidden and lays its children out as a plain
   block. That let the contact <ul> grow to its full content height (5000px+) and
   never scroll. Turn the pane into a full-height flex column so the list itself
   becomes the bounded scroll region — matching how the real Contacts app's list
   scrolls. :deep() is required because the pane is owned by NcAppContent. */
:deep(.splitpanes__pane-list) {
	display: flex;
	flex-direction: column;
	min-height: 0;
}

.crm-list-header {
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3) calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 13);
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--color-main-background);
	/* Stay pinned at the top; never shrink. */
	flex: 0 0 auto;
}

.crm-loading { margin: calc(var(--default-grid-baseline, 4px) * 6) auto; display: block; }

.crm-contacts-list {
	/* The scroll region: take the remaining height and scroll within it.
	   min-height:0 lets a flex child shrink below its content height so
	   overflow can actually kick in. */
	flex: 1 1 auto;
	min-height: 0;
	overflow-y: auto;
	padding: 0 calc(var(--default-grid-baseline, 4px) * 1);
	margin: 0;
}

/* Virtualise paint: the browser skips layout/paint for off-screen rows, so
   the full address book scrolls smoothly even at a few thousand contacts.
   contain-intrinsic-size keeps the scrollbar proportional before a row has
   ever been rendered. */
.crm-contacts-list :deep(.crm-contact-row) {
	content-visibility: auto;
	contain-intrinsic-size: auto 56px;
}
</style>
