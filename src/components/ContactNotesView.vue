<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div ref="rootEl" class="crm-contact-notes-view">
		<div class="crm-view-header">
			<div class="crm-contact-info">
				<ContactAvatar :uid="contact.uid" :name="contact.name" :photo="contact.photo" :is-user="contact.isUser" :size="48" />
				<div class="crm-contact-meta">
					<h1>{{ contact.name }}</h1>
					<span v-if="contact.email" class="crm-contact-email">{{ contact.email }}</span>
				</div>
			</div>
			<div class="crm-header-actions">
				<NcButton type="tertiary" :aria-label="sortAriaLabel" @click="toggleSort">
					<template #icon>
						<IconSortDescending v-if="notesStore.sort === 'newest'" :size="20" />
						<IconSortAscending v-else :size="20" />
					</template>
					{{ sortLabel }}
				</NcButton>
				<NcButton type="primary" @click="notesStore.openModal(null)">
					<template #icon><IconPlus :size="20" /></template>
					{{ t('crm_notes', 'Add note') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="notesStore.loading && !notesStore.contactNotes.length" :size="32" />
		<NcEmptyContent v-else-if="notesStore.contactNotesError && !notesStore.contactNotes.length"
			:name="t('crm_notes', 'Could not load notes')"
			:description="t('crm_notes', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="reload">{{ t('crm_notes', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!notesStore.contactNotes.length"
			:name="t('crm_notes', 'No notes yet')"
			:description="t('crm_notes', 'Add a note for this contact')">
			<template #icon><IconNote :size="48" /></template>
		</NcEmptyContent>
		<template v-else>
			<NoteItem v-for="note in notesStore.contactNotes"
				:key="note.id"
				:note="note"
				:deleting="notesStore.isDeleting(note.id)"
				@edit="notesStore.openModal(note)"
				@delete="onDelete(note)" />
			<div v-if="notesStore.contactNotesHasMore" class="crm-load-more">
				<NcButton :disabled="notesStore.loadingMore" @click="onLoadMore">
					{{ notesStore.loadingMore ? t('crm_notes', 'Loading…') : t('crm_notes', 'Load more') }}
				</NcButton>
			</div>
		</template>
		<!-- Announce newly-loaded notes to screen readers (no visual change). -->
		<p class="crm-visually-hidden" aria-live="polite" role="status">{{ loadMoreStatus }}</p>

		<NoteModal v-if="notesStore.showModal" :default-contact="contact" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { computed, ref, watch, onMounted } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { withScrollPreserved } from '../utils/scroll.js'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import IconPlus from 'vue-material-design-icons/Plus.vue'
import IconNote from 'vue-material-design-icons/Note.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconSortDescending from 'vue-material-design-icons/SortClockDescending.vue'
import IconSortAscending from 'vue-material-design-icons/SortClockAscending.vue'
import ContactAvatar from './ContactAvatar.vue'
import NoteItem from './NoteItem.vue'
import NoteModal from './NoteModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useNotesStore } from '../stores/notes.js'
import { useContactsStore } from '../stores/contacts.js'

const notesStore = useNotesStore()
const contactsStore = useContactsStore()

// Imperative handle to the declarative confirm dialog.
const confirmDialog = ref(null)

// Root element, used to locate the scroll container for load-more scroll
// preservation.
const rootEl = ref(null)

// Use computed so the displayed contact always reflects the current selection
const contact = computed(() => contactsStore.currentContact)

// The button shows the order currently in effect; clicking flips to the other.
const sortLabel = computed(() => notesStore.sort === 'oldest'
	? t('crm_notes', 'Oldest first')
	: t('crm_notes', 'Newest first'))

// Tell screen-reader users what the click will do, not just the current state.
const sortAriaLabel = computed(() => notesStore.sort === 'newest'
	? t('crm_notes', 'Sorted newest first. Click to sort oldest first.')
	: t('crm_notes', 'Sorted oldest first. Click to sort newest first.'))

function toggleSort() {
	notesStore.setSort(notesStore.sort === 'newest' ? 'oldest' : 'newest')
	if (contact.value) notesStore.loadForContact(contact.value.uid).catch(() => {})
}

// Live-region text announced to screen readers after "Load more" appends notes.
const loadMoreStatus = ref('')

onMounted(() => {
	if (contact.value) notesStore.loadForContact(contact.value.uid).catch(() => {})
})
watch(() => contactsStore.currentContact, (c) => {
	notesStore.contactNotes = []
	if (c) notesStore.loadForContact(c.uid).catch(() => {})
})

function reload() {
	if (contact.value) notesStore.loadForContact(contact.value.uid).catch(() => {})
}

async function onLoadMore() {
	const before = notesStore.contactNotes.length
	try {
		// Appending the next page must not yank the viewport back to the top —
		// keep the user reading where they were. Rows keep their stable
		// :key="note.id" so only appended items mount; we still restore the
		// scroll container's scrollTop after render. See utils/scroll.js.
		await withScrollPreserved(rootEl.value, () => notesStore.loadMoreContactNotes())
		const added = notesStore.contactNotes.length - before
		if (added > 0) {
			loadMoreStatus.value = n('crm_notes', '%n more note loaded', '%n more notes loaded', added)
		}
	} catch {
		showError(t('crm_notes', 'Failed to load more notes.'))
	}
}

async function onDelete(note) {
	const ok = await confirmDialog.value?.show({
		message: t('crm_notes', 'Delete this note?'),
		name: t('crm_notes', 'Delete note'),
		confirmLabel: t('crm_notes', 'Delete'),
	})
	if (!ok) return
	try {
		await notesStore.remove(note.id, contact.value?.uid)
	} catch {
		showError(t('crm_notes', 'Failed to delete note.'))
	}
}
</script>

<style scoped>
.crm-contact-notes-view {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 860px;
}

.crm-view-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 5);
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-contact-info {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-header-actions {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size rather than the larger user-agent h1 default. */
.crm-contact-meta h1 {
	margin: 0;
	font-size: calc(var(--default-font-size, 15px) * 1.3);
	font-weight: bold;
}

.crm-contact-email {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
}

.crm-load-more {
	display: flex;
	justify-content: center;
	padding: calc(var(--default-grid-baseline, 4px) * 4) 0;
}

.crm-visually-hidden {
	position: absolute;
	width: 1px;
	height: 1px;
	margin: -1px;
	padding: 0;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
