<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-all-notes-view">
		<div class="crm-view-header">
			<h2>{{ t('crm_notes', 'All notes') }}</h2>
		</div>
		<NcLoadingIcon v-if="notesStore.loading && !notesStore.allNotes.length" :size="32" />
		<NcEmptyContent v-else-if="notesStore.allNotesError && !notesStore.allNotes.length"
			:name="t('crm_notes', 'Could not load notes')"
			:description="t('crm_notes', 'Something went wrong while loading. Please try again.')">
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="notesStore.loadAll()">{{ t('crm_notes', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!notesStore.allNotes.length"
			:name="t('crm_notes', 'No notes yet')"
			:description="t('crm_notes', 'Open a contact to add your first note.')">
			<template #icon><IconNote :size="48" /></template>
			<template #action>
				<NcButton type="primary" @click="$emit('go-to-contacts')">
					{{ t('crm_notes', 'Browse contacts') }}
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
					{{ notesStore.loadingMore ? t('crm_notes', 'Loading…') : t('crm_notes', 'Load more') }}
				</NcButton>
			</div>
		</template>
		<!-- Announce newly-loaded notes to screen readers (no visual change). -->
		<p class="crm-visually-hidden" aria-live="polite" role="status">{{ loadMoreStatus }}</p>
		<NoteModal v-if="notesStore.showModal" />
	</div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { confirmDestructive } from '../utils/confirm.js'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconNote from 'vue-material-design-icons/Note.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import NoteItem from './NoteItem.vue'
import NoteModal from './NoteModal.vue'
import { useNotesStore } from '../stores/notes.js'
import { useContactsStore } from '../stores/contacts.js'

defineEmits(['go-to-contacts'])

const notesStore = useNotesStore()
const contactsStore = useContactsStore()

// Live-region text announced to screen readers after "Load more" appends notes.
const loadMoreStatus = ref('')

onMounted(() => notesStore.loadAll().catch(() => {}))

async function onLoadMore() {
	const before = notesStore.allNotes.length
	try {
		await notesStore.loadMoreNotes()
		const added = notesStore.allNotes.length - before
		if (added > 0) {
			loadMoreStatus.value = n('crm_notes', '%n more note loaded', '%n more notes loaded', added)
		}
	} catch {
		showError(t('crm_notes', 'Failed to load more notes.'))
	}
}

async function onDelete(note) {
	const ok = await confirmDestructive(
		t('crm_notes', 'Delete this note?'),
		t('crm_notes', 'Delete note'),
		t('crm_notes', 'Delete'),
	)
	if (!ok) return
	try {
		await notesStore.remove(note.id)
	} catch {
		showError(t('crm_notes', 'Failed to delete note.'))
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
	/* The all-notes header has no trailing action (unlike ContactNotesView /
	   NoteTypesView), so it is a simple left-aligned heading. Avoid
	   justify-content: space-between here — with a single child it does nothing,
	   and a future action would jump to the far right unexpectedly. */
	display: flex;
	align-items: center;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
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
