<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div ref="rootEl" class="crm-all-notes-view">
		<div class="crm-view-header">
			<h1>{{ t('touchpoint', 'All notes') }}</h1>
			<NcTextField
				v-model="searchInput"
				class="crm-search-field"
				type="search"
				:label="t('touchpoint', 'Search notes')"
				:placeholder="t('touchpoint', 'Search notes…')"
				:aria-label="t('touchpoint', 'Search notes')"
				:show-trailing-button="searchInput.length > 0"
				:trailing-button-label="t('touchpoint', 'Clear search')"
				@trailing-button-click="clearSearch">
				<!-- Leading magnifier mirrors the search empty-states' iconography
				     so the field reads as a search box even when the placeholder
				     is hidden (on focus/typing). -->
				<template #icon>
					<IconMagnify :size="16" />
				</template>
				<template #trailing-button-icon>
					<IconClose :size="16" />
				</template>
			</NcTextField>
			<NcButton variant="tertiary" :aria-label="sortAriaLabel" @click="toggleSort">
				<template #icon>
					<IconSortDescending v-if="notesStore.sort === 'newest'" :size="20" />
					<IconSortAscending v-else :size="20" />
				</template>
				{{ sortLabel }}
			</NcButton>
		</div>
		<template v-if="searchUnavailable">
			<!-- Public mode: NoteService::search() deliberately returns [] (it would
			     otherwise leak other users' notes), so the search box can never
			     return results. Explain that rather than showing "No notes found". -->
			<NcEmptyContent
				:name="t('touchpoint', 'Search is unavailable')"
				:description="t('touchpoint', 'Search is disabled while notes are shared publicly.')">
				<template #icon><IconMagnify :size="48" /></template>
			</NcEmptyContent>
		</template>
		<template v-else-if="notesStore.isSearching">
			<NcEmptyContent v-if="searchTooShort"
				:name="t('touchpoint', 'Keep typing')"
				:description="t('touchpoint', 'Type at least 2 characters to search.')">
				<template #icon><IconMagnify :size="48" /></template>
			</NcEmptyContent>
			<NcLoadingIcon v-else-if="searchPending" :size="32" />
			<NcEmptyContent v-else-if="notesStore.searchError"
				:name="t('touchpoint', 'Search failed')"
				:description="searchErrorDescription">
				<template #icon><IconAlert :size="48" /></template>
				<template v-if="notesStore.searchError !== 'ratelimited'" #action>
					<NcButton @click="notesStore.runSearch()">{{ t('touchpoint', 'Retry') }}</NcButton>
				</template>
			</NcEmptyContent>
			<NcEmptyContent v-else-if="!notesStore.searchResults.length"
				:name="t('touchpoint', 'No notes found')"
				:description="t('touchpoint', 'Try a different search term.')">
				<template #icon><IconMagnify :size="48" /></template>
				<template #action>
					<NcButton @click="clearSearch">{{ t('touchpoint', 'Clear search') }}</NcButton>
				</template>
			</NcEmptyContent>
			<template v-else>
				<ul>
					<NoteItem v-for="note in notesStore.searchResults"
						:key="note.id"
						:note="note"
						:show-contact="true"
						:deleting="notesStore.isDeleting(note.id)"
						@edit="notesStore.openModal(note)"
						@delete="onDelete(note)"
						@contact-click="onContactClick" />
				</ul>
				<div v-if="notesStore.searchHasMore" class="crm-load-more">
					<NcButton :disabled="notesStore.searchLoadingMore" @click="onLoadMoreSearch">
						{{ notesStore.searchLoadingMore ? t('touchpoint', 'Loading…') : t('touchpoint', 'Load more') }}
					</NcButton>
				</div>
			</template>
		</template>
		<template v-else>
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
					<NcButton variant="primary" @click="$emit('go-to-contacts')">
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
		</template>
		<!-- Single live region for screen-reader status. The two modes (search vs.
		     load-more) are mutually exclusive in the UI; collapsing to one region
		     prevents a stale message in one node being read alongside the other. -->
		<p class="crm-visually-hidden" aria-live="polite" role="status">{{ liveStatus }}</p>
		<NoteModal v-if="notesStore.showModal" />
		<ConfirmDialog ref="confirmDialog" />
	</div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { withScrollPreserved } from '../utils/scroll.js'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import IconNote from 'vue-material-design-icons/Note.vue'
import IconMagnify from 'vue-material-design-icons/Magnify.vue'
import IconAlert from 'vue-material-design-icons/AlertCircle.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconSortDescending from 'vue-material-design-icons/SortClockDescending.vue'
import IconSortAscending from 'vue-material-design-icons/SortClockAscending.vue'
import NoteItem from './NoteItem.vue'
import NoteModal from './NoteModal.vue'
import ConfirmDialog from './ConfirmDialog.vue'
import { useNotesStore } from '../stores/notes.js'
import { useContactsStore } from '../stores/contacts.js'
import { useSettingsStore } from '../stores/settings.js'

// Minimum trimmed length before a search fires. Single-character terms become
// '%a%' LIKE scans over title AND content for the whole accessible set — heavy
// to serve and near-useless to the user — and each one burns the endpoint's
// rate-limit budget. Treat shorter input as "keep typing".
const MIN_SEARCH_LENGTH = 2

defineEmits(['go-to-contacts'])

const notesStore = useNotesStore()
const contactsStore = useContactsStore()
const settingsStore = useSettingsStore()

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
	// The sort control is rendered outside both list branches and is usable
	// during a search, so it must re-run whichever list is currently shown.
	// runSearch() reads notesStore.sort and bumps _searchSeq, so the re-order is
	// race-safe. Gate on the same MIN_SEARCH_LENGTH the debounce watch uses: a
	// sub-minimum query shows the "Keep typing" state with no result list to
	// re-order, so firing a '%a%' scan here would bypass that guard and burn
	// rate-limit budget for a result the user never sees. Keep the background
	// all-notes list in the new order too.
	if (notesStore.isSearching
		&& !settingsStore.notesPublic
		&& notesStore.searchQuery.trim().length >= MIN_SEARCH_LENGTH) {
		notesStore.runSearch()
	}
	notesStore.loadAll().catch(() => {})
}

// Single live-region text announced to screen readers. Search status takes
// precedence while searching; otherwise it reflects the last load-more append.
const loadMoreStatus = ref('')

// Search is impossible to satisfy in public mode (the service returns [] by
// design to avoid leaking other users' notes), so surface that explicitly when
// the user actually starts a search rather than showing a generic empty state.
const searchUnavailable = computed(() => settingsStore.notesPublic && notesStore.isSearching)

// True while the trimmed query is non-empty but below the minimum length.
const searchTooShort = computed(() =>
	notesStore.isSearching && notesStore.searchQuery.trim().length < MIN_SEARCH_LENGTH)

// True from the moment a long-enough term is registered until the request
// resolves, covering the debounce window so the empty "No notes found" state
// never flashes before the first response.
const searchPending = computed(() => notesStore.searchLoading || debouncePending.value)

const searchErrorDescription = computed(() => {
	switch (notesStore.searchError) {
	case 'ratelimited':
		return t('touchpoint', 'Too many searches — please wait a moment and try again.')
	case 'toolong':
		return t('touchpoint', 'Search query is too long (maximum 500 characters).')
	default:
		return t('touchpoint', 'Something went wrong. Please try again.')
	}
})

const searchInput = ref('')
let debounceTimer = null
// True while a debounced search is scheduled but has not fired yet. Drives the
// loading state during the 300 ms window so the empty state cannot flash.
const debouncePending = ref(false)

watch(searchInput, (val) => {
	notesStore.setSearchQuery(val)
	clearTimeout(debounceTimer)
	debouncePending.value = false
	// In public mode the backend deliberately returns [] for every search, so do
	// not fire a request at all; the searchUnavailable empty state explains it.
	if (settingsStore.notesPublic) {
		notesStore.cancelSearch()
		return
	}
	// Below the minimum length we do not search at all; clear any stale results
	// and show the "keep typing" hint instead of firing a query.
	if (val.trim().length < MIN_SEARCH_LENGTH) {
		notesStore.cancelSearch()
		return
	}
	debouncePending.value = true
	debounceTimer = setTimeout(() => {
		debouncePending.value = false
		notesStore.runSearch()
	}, 300)
})

// Single combined live-region text. Search state takes precedence while a
// search is active; otherwise the load-more append message is announced.
const liveStatus = computed(() => {
	if (settingsStore.notesPublic && notesStore.isSearching) {
		return t('touchpoint', 'Search is disabled while notes are shared publicly.')
	}
	if (notesStore.isSearching) {
		if (searchTooShort.value) {
			return t('touchpoint', 'Type at least 2 characters to search.')
		}
		if (searchPending.value) {
			return t('touchpoint', 'Searching…')
		}
		if (notesStore.searchError) {
			return t('touchpoint', 'Search failed.')
		}
		const c = notesStore.searchResults.length
		if (c === 0) {
			return t('touchpoint', 'No notes found.')
		}
		// When the page is full there may be more matches than shown; announce
		// "at least" semantics rather than a precise (and wrong) total.
		return notesStore.searchHasMore
			? n('touchpoint', 'Showing first %n note', 'Showing first %n notes', c)
			: n('touchpoint', '%n note found', '%n notes found', c)
	}
	return loadMoreStatus.value
})

onMounted(() => notesStore.loadAll().catch(() => {}))

onUnmounted(() => {
	clearTimeout(debounceTimer)
	debouncePending.value = false
	// Search is a per-view ephemeral filter: searchInput is a component-local ref
	// that resets to '' on every (re)mount, but searchQuery/searchResults live in
	// the singleton store. AllNotesView unmounts/remounts on section changes and
	// on contact select/deselect, so merely cancelling the in-flight XHR
	// (cancelSearch) would leave stale searchResults visible behind an empty
	// search box on remount, with no affordance to recover. resetSearch() clears
	// the whole search state (and bumps _searchSeq to invalidate in-flight XHRs),
	// so a remount always starts from the clean all-notes baseline that matches
	// the empty input.
	notesStore.resetSearch()
})

function clearSearch() {
	// Cancel any pending debounce and reset all search state so the normal note
	// list reappears immediately without the 300 ms debounce delay. The watch on
	// searchInput would schedule a debounced runSearch(); we pre-empt it here.
	// All store-state ownership stays inside resetSearch().
	clearTimeout(debounceTimer)
	debouncePending.value = false
	searchInput.value = ''
	notesStore.resetSearch()
}

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

async function onLoadMoreSearch() {
	const before = notesStore.searchResults.length
	try {
		await withScrollPreserved(rootEl.value, () => notesStore.loadMoreSearch())
		const added = notesStore.searchResults.length - before
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
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 3);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 4);
}

@media (max-width: 480px) {
	.crm-view-header {
		flex-direction: column;
		align-items: stretch;
	}

	.crm-search-field {
		flex: 1 1 auto;
	}
}

/* Promoted to h1 so the page has a level-1 heading for AT; keep the established
   section-title size (the app styles these as section titles, not browser-default
   h1) rather than the larger user-agent h1 size. NC's own
   `.app-navigation-toggle` floats over the top-left of the content area
   (~34px tall) — a zero top margin lets this page's heading render underneath
   it, clipping its leading text, so restore top clearance explicitly rather
   than zeroing it out. */
.crm-view-header h1 {
	margin: calc(var(--default-grid-baseline, 4px) * 6) 0 0 0;
	font-size: calc(var(--default-font-size, 14px) * 1.3);
	font-weight: bold;
	/* flex-shrink:0 keeps the title at its natural width so longer-locale
	   headings (e.g. de 'Alle Notizen') render in full; the search field's
	   flex:1 1 absorbs the remaining width. nowrap/ellipsis stay only as a
	   last-resort guard for pathologically long strings, with no aggressive
	   percentage cap clipping the primary page title at desktop widths. */
	flex-shrink: 0;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
}

.crm-search-field {
	/* Use NC baseline token so flex-basis scales with the design-system grid.
	   calc(4px * 50) = 200px at the default 4px baseline. */
	flex: 1 1 calc(var(--default-grid-baseline, 4px) * 50);
	min-width: 0;
}

/* Suppress the browser's built-in clear (×) button on type=search inputs.
   NcTextField provides its own trailing clear button; showing both would
   duplicate the affordance and create two separate clear code paths. */
.crm-search-field input[type='search']::-webkit-search-cancel-button,
.crm-search-field input[type='search']::-webkit-search-decoration {
	display: none;
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
