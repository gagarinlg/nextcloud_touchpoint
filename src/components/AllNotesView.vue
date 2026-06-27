<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div ref="rootEl" class="crm-all-notes-view">
		<div class="crm-view-header">
			<h1>{{ t('touchpoint', 'All notes') }}</h1>
			<NcButton type="tertiary" :aria-label="sortAriaLabel" @click="toggleSort">
				<template #icon>
					<IconSortDescending v-if="notesStore.sort === 'newest'" :size="20" />
					<IconSortAscending v-else :size="20" />
				</template>
				{{ sortLabel }}
			</NcButton>
		</div>
		<NcLoadingIcon v-if="notesStore.loading && !notesStore.allNotes.length" :size="32" />
		<NcEmptyContent v-else-if="notesStore.allNotesError && !notesStore.allNotes.length"
			:name="t('touchpoint', 'Could not load notes')"
			:description="t('touchpoint', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="notesStore.loadAll()">{{ t('touchpoint', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!notesStore.allNotes.length"
			:name="t('touchpoint', 'No notes yet')"
			:description="t('touchpoint', 'Open a contact to add your first note.')">
			<template #icon><IconNote :size="48" /></template>
			<template #action>
				<NcButton type="primary" @click="$emit('go-to-contacts')">
					{{ t('touchpoint', 'Browse contacts') }}
				</NcButton>
			</template>
		</NcEmptyContent>
		<template v-else>
			<NoteItem v-for="note in notesStore.allNotes"
				:key="note.id"
				:note="note"
				:show-contact="true"
				:deleting="notesStore.isDeleting(note.id)"
				@edit="notesStore.openModal(note)"
				@delete="onDelete(note)"
				@contact-click="onContactClick" />
			<div v-if="notesStore.allNotesHasMore" class="crm-load-more">
				<NcButton :disabled="notesStore.loadingMore" @click="onLoadMore">
					{{ notesStore.loadingMore ? t('touchpoint', 'Loading…') : t('touchpoint', 'Load more') }}
				</NcButton>
			</div>
		</template>
		<!-- Announce newly-loaded notes to screen readers (no visual change). -->
		<p class="crm-visually-hidden" aria-live="polite" role="status">{{ loadMoreStatus }}</p>
		<NoteModal v-if="notesStore.showModal" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { withScrollPreserved } from '../utils/scroll.js'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconNote from 'vue-material-design-icons/Note.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconSortDescending from 'vue-material-design-icons/SortClockDescending.vue'
import IconSortAscending from 'vue-material-design-icons/SortClockAscending.vue'
import NoteItem from './NoteItem.vue'
import NoteModal from './NoteModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useNotesStore } from '../stores/notes.js'
import { useContactsStore } from '../stores/contacts.js'

defineEmits(['go-to-contacts'])

const notesStore = useNotesStore()
const contactsStore = useContactsStore()

// Imperative handle to the declarative confirm dialog (replaces the old
// imperative @nextcloud/dialogs builder that crashed at runtime).
const confirmDialog = ref(null)

// Root element, used to locate the scroll container for load-more scroll
// preservation.
const rootEl = ref(null)

// The button shows the order currently in effect; clicking flips to the other.
const sortLabel = computed(() => notesStore.sort === 'oldest'
	? t('touchpoint', 'Oldest first')
	: t('touchpoint', 'Newest first'))

// Tell screen-reader users what the click will do, not just the current state.
const sortAriaLabel = computed(() => notesStore.sort === 'newest'
	? t('touchpoint', 'Sorted newest first. Click to sort oldest first.')
	: t('touchpoint', 'Sorted oldest first. Click to sort newest first.'))

function toggleSort() {
	notesStore.setSort(notesStore.sort === 'newest' ? 'oldest' : 'newest')
	notesStore.loadAll().catch(() => {})
}

// Live-region text announced to screen readers after "Load more" appends notes.
const loadMoreStatus = ref('')

onMounted(() => notesStore.loadAll().catch(() => {}))

async function onLoadMore() {
	const before = notesStore.allNotes.length
	try {
		// Keep the viewport where the user was reading: capture and restore the
		// scroll container's scrollTop around the append. Rows keep their stable
		// :key="note.id", so only the newly loaded notes mount (below the fold).
		await withScrollPreserved(rootEl.value, () => notesStore.loadMoreNotes())
		const added = notesStore.allNotes.length - before
		if (added > 0) {
			loadMoreStatus.value = n('touchpoint', '%n more note loaded', '%n more notes loaded', added)
		}
	} catch {
		showError(t('touchpoint', 'Failed to load more notes.'))
	}
}

async function onDelete(note) {
	const ok = await confirmDialog.value?.show({
		message: t('touchpoint', 'Delete this note?'),
		name: t('touchpoint', 'Delete note'),
		confirmLabel: t('touchpoint', 'Delete'),
	})
	if (!ok) return
	try {
		await notesStore.remove(note.id)
	} catch {
		showError(t('touchpoint', 'Failed to delete note.'))
	}
}

function onContactClick(uid) {
	const contact = contactsStore.byUid(uid)
	if (contact) contactsStore.select(contact)
}
</script>

<style scoped>
.crm-all-notes-view {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	max-width: 860px;
}

.crm-view-header {
	/* Heading on the left, the sort control on the right. */
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size (the app styles these as section titles, not browser-default
   h1) rather than the larger user-agent h1 size. */
.crm-view-header h1 {
	margin: 0;
	font-size: calc(var(--default-font-size, 14px) * 1.3);
	font-weight: bold;
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
