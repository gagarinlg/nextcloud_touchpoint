<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div ref="rootEl" class="crm-contact-notes-view">
		<div class="crm-view-header">
			<div class="crm-contact-info">
				<ContactAvatar :uid="contact.uid" :name="contact.name" :photo="contact.photo" :is-user="contact.isUser" :size="48" />
				<div class="crm-contact-meta">
					<h1>{{ contact.name || contact.uid }}</h1>
					<span v-if="contact.email" class="crm-contact-email">{{ contact.email }}</span>
				</div>
			</div>
			<div class="crm-header-actions">
				<NcButton variant="tertiary" :aria-label="sortAriaLabel" @click="toggleSort">
					<template #icon>
						<IconSortDescending v-if="notesStore.sort === 'newest'" :size="20" />
						<IconSortAscending v-else :size="20" />
					</template>
					{{ sortLabel }}
				</NcButton>
				<NcButton variant="primary" @click="notesStore.openModal(null)">
					<template #icon><IconPlus :size="20" /></template>
					{{ t('touchpoint', 'Add note') }}
				</NcButton>
			</div>
		</div>

		<div v-if="contactsAppEnabled && contact.email" class="crm-contact-card-section">
			<NcButton variant="tertiary"
				:aria-expanded="showCard ? 'true' : 'false'"
				aria-controls="crm-contact-card-region"
				@click="showCard = !showCard"
			>
				<template #icon>
					<IconChevronDown v-if="showCard" :size="20" />
					<IconChevronRight v-else :size="20" />
				</template>
				{{ showCard ? t('touchpoint', 'Hide contact details') : t('touchpoint', 'Show contact details') }}
			</NcButton>
			<!-- The region is always present so aria-controls always resolves to a
			real element; the embedded Contacts card is lazily mounted inside it
			only once expanded (keyed by uid so switching contacts remounts). -->
			<div id="crm-contact-card-region">
				<ContactCard v-if="showCard" :key="contact.uid" :email="contact.email" />
			</div>
		</div>

		<NcLoadingIcon v-if="notesStore.loading && !notesStore.contactNotes.length" :size="32" />
		<NcEmptyContent v-else-if="notesStore.contactNotesError && !notesStore.contactNotes.length"
			:name="t('touchpoint', 'Could not load notes')"
			:description="t('touchpoint', 'Something went wrong while loading. Please try again.')"
		>
			<template #icon><IconAlert :size="48" /></template>
			<template #action>
				<NcButton @click="reload">{{ t('touchpoint', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<NcEmptyContent v-else-if="!notesStore.contactNotes.length"
			:name="t('touchpoint', 'No notes yet')"
			:description="t('touchpoint', 'Add a note for this contact')"
		>
			<template #icon><IconNote :size="48" /></template>
		</NcEmptyContent>
		<template v-else>
			<NoteItem v-for="note in notesStore.contactNotes"
				:key="note.id"
				:note="note"
				:deleting="notesStore.isDeleting(note.id)"
				:highlighted="note.id === notesStore.highlightNoteId"
				@edit="notesStore.openModal(note)"
				@delete="onDelete(note)"
			/>
			<div v-if="notesStore.contactNotesHasMore" class="crm-load-more">
				<NcButton :disabled="notesStore.loadingMore" @click="onLoadMore">
					{{ notesStore.loadingMore ? t('touchpoint', 'Loading…') : t('touchpoint', 'Load more') }}
				</NcButton>
			</div>
		</template>
		<!-- Announce newly-loaded notes, and #note/{id} deep-link outcomes, to
		screen readers (no visual change). -->
		<p class="crm-visually-hidden" aria-live="polite" role="status">{{ viewStatus }}</p>

		<NoteModal v-if="notesStore.showModal" :default-contact="contact" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { computed, ref, watch, onMounted, onUnmounted, nextTick } from 'vue'
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
import ContactCard from './ContactCard.vue'
import IconChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import IconChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import { loadState } from '@nextcloud/initial-state'
import { useNotesStore } from '../stores/notes.js'
import { useContactsStore } from '../stores/contacts.js'

const notesStore = useNotesStore()
const contactsStore = useContactsStore()

// Whether the embedded contact-card integration is available (Contacts app
// installed + its OCA bundle loaded on this page). Provided by PageController.
const contactsAppEnabled = loadState('touchpoint', 'contactsAppEnabled', false)
const showCard = ref(false)

// Imperative handle to the declarative confirm dialog.
const confirmDialog = ref(null)

// Root element, used to locate the scroll container for load-more scroll
// preservation.
const rootEl = ref(null)

// Use computed so the displayed contact always reflects the current selection
const contact = computed(() => contactsStore.currentContact)

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
	if (contact.value) notesStore.loadForContact(contact.value.uid).catch(() => {})
}

// Live-region text announced to screen readers: after "Load more" appends
// notes, and after a #note/{id} deep link finishes locating (or fails to
// locate) its target note. Named generically (not loadMoreStatus) since it now
// serves both purposes — only one is ever relevant at a time.
const viewStatus = ref('')

// Safety cap on how many extra pages applyHighlight() will fetch while
// hunting for a #note/{id} deep-link target that isn't on the first page —
// bounds worst-case network calls for a note buried deep in a large history
// instead of looping until contactNotesHasMore turns false naturally.
const MAX_HIGHLIGHT_LOAD_PAGES = 20

// Guards the highlight pagination loop and its trailing timer against a
// contact switch (watch() below) or component unmount that happens while
// applyHighlight() is still paging: without this, a stale run could resolve
// after the store has already moved on to a different contact's notes and
// wrongly scroll/focus/clear-highlight on top of the new contact's view, or
// leave its setTimeout firing after the component is gone.
let highlightRunSeq = 0
let pendingClearHighlightTimer = null

async function loadAndApplyHighlight(uid) {
	const seq = ++highlightRunSeq
	await notesStore.loadForContact(uid)
	if (seq !== highlightRunSeq) return // superseded by a newer contact switch
	await applyHighlight(seq)
}

// If a #note/{id} deep link targets a note that isn't on the first loaded
// page, keep paging (bounded) until it's found or there is no more to load.
// Once found (or given up), scroll it into view and clear the store flag so
// the highlight/scroll never re-triggers on a later re-render of this contact.
async function applyHighlight(seq) {
	const targetId = notesStore.highlightNoteId
	if (targetId == null) return
	let pages = 0
	while (
		!notesStore.contactNotes.some(nt => nt.id === targetId)
		&& notesStore.contactNotesHasMore
		&& pages < MAX_HIGHLIGHT_LOAD_PAGES
	) {
		await notesStore.loadMoreContactNotes()
		if (seq !== highlightRunSeq) return // superseded mid-loop; let the newer run own the flag
		pages++
	}
	if (seq !== highlightRunSeq) return
	await nextTick()
	const el = document.getElementById('crm-note-' + targetId)
	if (el) {
		// Respect prefers-reduced-motion, matching the established convention in
		// this codebase (AdminSettings.vue, NoteItem.vue, contacts-integration.js):
		// jump straight there instead of animating the scroll.
		const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
		el.scrollIntoView({ behavior: reducedMotion ? 'auto' : 'smooth', block: 'center' })
		// The visual highlight (border/background wash, see NoteItem.vue's
		// .is-highlighted) is the only feedback a mouse user needs, but a
		// keyboard-only or screen-reader user who opened this via a
		// notification link has no other way to know "this is the note the
		// notification was about" — move DOM focus there (NoteItem.vue gives
		// the highlighted note tabindex="-1" for exactly this) and announce it
		// via the existing live region.
		el.focus({ preventScroll: true })
		const targetNote = notesStore.contactNotes.find(nt => nt.id === targetId)
		viewStatus.value = t('touchpoint', 'Opened note "{title}"', { title: targetNote?.title || '' })
	} else {
		// Not found even after exhausting pagination (deleted since, or belongs
		// to a different contact than expected) — nothing to scroll/focus to,
		// but still tell AT users the deep link didn't resolve rather than
		// leaving them to wonder why nothing happened.
		viewStatus.value = t('touchpoint', 'The note from this link could not be found.')
	}
	// The highlight flag is cleared after a delay so it doesn't linger
	// indefinitely. The clear itself is deferred so the highlighted prop has
	// had a render frame to apply first; the CSS transition (1.5s) then fades
	// visibly instead of the class flipping off before paint. Tracked in
	// pendingClearHighlightTimer so onUnmounted can cancel it if the view goes
	// away first, and guarded by `seq` so a superseded run's timer can't clear
	// a highlight that a newer run just set.
	if (pendingClearHighlightTimer != null) window.clearTimeout(pendingClearHighlightTimer)
	pendingClearHighlightTimer = window.setTimeout(() => {
		pendingClearHighlightTimer = null
		if (seq === highlightRunSeq) notesStore.clearHighlightNote()
	}, 2000)
}

onMounted(() => {
	if (contact.value) loadAndApplyHighlight(contact.value.uid).catch(() => {})
})
watch(() => contactsStore.currentContact, (c) => {
	notesStore.contactNotes = []
	if (c) loadAndApplyHighlight(c.uid).catch(() => {})
})
onUnmounted(() => {
	// Invalidate any in-flight pagination-hunt loop or trailing clear-timer so
	// they cannot act on this (now-gone) view's DOM or another contact's notes
	// after this component is torn down (e.g. navigating away from the
	// Contacts tab entirely while applyHighlight() is still paging).
	highlightRunSeq++
	if (pendingClearHighlightTimer != null) {
		window.clearTimeout(pendingClearHighlightTimer)
		pendingClearHighlightTimer = null
	}
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
			viewStatus.value = n('touchpoint', '%n more note loaded', '%n more notes loaded', added)
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
		await notesStore.remove(note.id, contact.value?.uid)
	} catch {
		showError(t('touchpoint', 'Failed to delete note.'))
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
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 5);
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-contact-info {
	display: flex;
	align-items: center;
	min-width: 0;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-contact-meta {
	min-width: 0;
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
	font-size: calc(var(--default-font-size, 14px) * 1.3);
	font-weight: bold;
	overflow-wrap: anywhere;
}

.crm-contact-email {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	overflow-wrap: anywhere;
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
