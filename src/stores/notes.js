/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { defineStore } from 'pinia'
import * as NoteService from '../services/NoteService.js'

const PAGE_SIZE = 50

export const useNotesStore = defineStore('notes', {
	state: () => ({
		allNotes: [],
		allNotesHasMore: false,
		allNotesOffset: 0,
		contactNotes: [],
		contactNotesHasMore: false,
		contactNotesOffset: 0,
		// Contact UID the loaded contactNotes page belongs to, so loadMore can
		// keep paging the right contact.
		contactNotesUid: null,
		// Sort direction for both the all-notes and contact-notes lists:
		// 'newest' (created_at descending, default) or 'oldest' (ascending).
		// Threaded to the API; defaults to newest on each load.
		sort: 'newest',
		loading: false,
		loadingMore: false,
		// True while save() has network calls in flight, so the modal's Save
		// button can disable itself and show progress — preventing a double-click
		// from creating duplicate notes / duplicate attachments.
		saving: false,
		// IDs of notes with a delete in flight, so each row can show its own
		// pending/disabled state instead of relying on the global loading flag.
		pendingDeleteIds: [],
		allNotesError: false,
		contactNotesError: false,
		showModal: false,
		editingNote: null,
		pendingFiles: [],   // { fileId, filePath, name } — not yet saved
		removedFileIds: [], // noteFileId values to remove on save
		searchQuery: '',
		searchResults: [],
		searchLoading: false,
		// false when there is no error; otherwise a discriminated reason string
		// ('ratelimited' | 'toolong' | 'generic') so the view can show an
		// actionable message instead of a single opaque "Something went wrong".
		searchError: false,
		searchOffset: 0,
		searchHasMore: false,
		// True while loadMoreSearch() has a request in flight, so the search
		// "Load more" button can show progress and disable itself.
		searchLoadingMore: false,
		// internal race guard — do not mutate externally; use cancelSearch() to
		// invalidate in-flight requests. JavaScript numbers become Infinity at
		// 2^53-1 (not wrap-around); at Infinity, all seq === this._searchSeq
		// checks would be Infinity === Infinity (true), breaking the guard. In
		// practice this requires ~285,000 years of continuous 1000-searches/sec
		// and is not a real risk. Mitigation if ever needed:
		// this._searchSeq = (this._searchSeq >= Number.MAX_SAFE_INTEGER) ? 0 : this._searchSeq + 1
		_searchSeq: 0,
	}),
	getters: {
		isSearching: (state) => state.searchQuery.trim().length > 0,
	},
	actions: {
		// Switch the sort direction. Validates to the two supported values so a
		// stray value can never be sent to the API; callers re-load afterwards.
		setSort(sort) {
			this.sort = sort === 'oldest' ? 'oldest' : 'newest'
		},
		async loadAll() {
			this.loading = true
			this.allNotesError = false
			this.allNotesOffset = 0
			this.allNotesHasMore = false
			try {
				const notes = await NoteService.getAllNotes(PAGE_SIZE, 0, this.sort)
				this.allNotes = notes
				this.allNotesOffset = notes.length
				this.allNotesHasMore = notes.length === PAGE_SIZE
			} catch (e) {
				this.allNotesError = true
				throw e
			} finally {
				this.loading = false
			}
		},
		async loadMoreNotes() {
			if (!this.allNotesHasMore || this.loading || this.loadingMore) return
			this.loadingMore = true
			try {
				const notes = await NoteService.getAllNotes(PAGE_SIZE, this.allNotesOffset, this.sort)
				this.allNotes.push(...notes)
				this.allNotesOffset += notes.length
				this.allNotesHasMore = notes.length === PAGE_SIZE
			} finally {
				this.loadingMore = false
			}
		},
		async loadForContact(uid) {
			this.loading = true
			this.contactNotesError = false
			this.contactNotesUid = uid
			this.contactNotesOffset = 0
			this.contactNotesHasMore = false
			try {
				const notes = await NoteService.getNotesByContact(uid, PAGE_SIZE, 0, this.sort)
				this.contactNotes = notes
				this.contactNotesOffset = notes.length
				this.contactNotesHasMore = notes.length === PAGE_SIZE
			} catch (e) {
				this.contactNotesError = true
				throw e
			} finally {
				this.loading = false
			}
		},
		async loadMoreContactNotes() {
			if (!this.contactNotesHasMore || this.loading || this.loadingMore || !this.contactNotesUid) return
			this.loadingMore = true
			try {
				const notes = await NoteService.getNotesByContact(
					this.contactNotesUid,
					PAGE_SIZE,
					this.contactNotesOffset,
					this.sort,
				)
				this.contactNotes.push(...notes)
				this.contactNotesOffset += notes.length
				this.contactNotesHasMore = notes.length === PAGE_SIZE
			} finally {
				this.loadingMore = false
			}
		},
		async save(payload, currentContactUid) {
			// Guard against re-entrant saves: an in-flight save (the multi-step
			// create/update + file sync below) must not be started twice, or the
			// second run creates a duplicate note and duplicate attachments.
			if (this.saving) return
			this.saving = true
			try {
				if (this.editingNote) {
					await NoteService.updateNote(this.editingNote.id, payload)
					// Handle file removals
					for (const id of this.removedFileIds) {
						await NoteService.removeFile(this.editingNote.id, id)
					}
					// Handle new file attachments
					for (const f of this.pendingFiles.filter(f => !f.noteFileId)) {
						await NoteService.addFile(this.editingNote.id, f.fileId || 0, f.filePath)
					}
				} else {
					const note = await NoteService.createNote(payload)
					for (const f of this.pendingFiles) {
						await NoteService.addFile(note.id, f.fileId || 0, f.filePath)
					}
				}
				this.closeModal()
				await this.loadAll()
				if (currentContactUid) {
					await this.loadForContact(currentContactUid)
				}
			} finally {
				this.saving = false
			}
		},
		// Legacy non-reactive helper; new computed state goes in getters: not here.
		isDeleting(id) {
			return this.pendingDeleteIds.includes(id)
		},
		async remove(id, currentContactUid) {
			if (this.pendingDeleteIds.includes(id)) return
			this.pendingDeleteIds.push(id)
			try {
				await NoteService.deleteNote(id)
				await this.loadAll()
				if (currentContactUid) {
					await this.loadForContact(currentContactUid)
				}
			} finally {
				this.pendingDeleteIds = this.pendingDeleteIds.filter(x => x !== id)
			}
		},
		openModal(note = null) {
			this.editingNote = note
			this.pendingFiles = note ? note.files.map(f => ({ ...f, noteFileId: f.id })) : []
			this.removedFileIds = []
			this.showModal = true
		},
		closeModal() {
			this.showModal = false
			this.editingNote = null
			this.pendingFiles = []
			this.removedFileIds = []
		},
		addPendingFile(filePath) {
			if (!this.pendingFiles.some(f => f.filePath === filePath)) {
				this.pendingFiles.push({ fileId: 0, filePath, name: filePath.split('/').pop() })
			}
		},
		removePendingFile(index) {
			const f = this.pendingFiles[index]
			if (f.noteFileId) {
				this.removedFileIds.push(f.noteFileId)
			}
			this.pendingFiles.splice(index, 1)
		},

		setSearchQuery(q) {
			this.searchQuery = q
		},

		// Map an axios error to a discriminated reason the view can explain.
		// 429: rate-limited (#[UserRateLimit] on the search endpoint); 400:
		// invalid input (e.g. term over MAX_SEARCH_TERM_LENGTH); otherwise generic.
		_searchErrorReason(e) {
			const status = e?.response?.status
			if (status === 429) return 'ratelimited'
			if (status === 400) return 'toolong'
			return 'generic'
		},

		// Increment _searchSeq to invalidate any in-flight runSearch() /
		// loadMoreSearch() call. Call from onUnmounted in AllNotesView to prevent
		// stale XHR responses from overwriting searchResults after component remount.
		cancelSearch() {
			this._searchSeq++
			this.searchLoading = false
			this.searchLoadingMore = false
		},

		// Reset all search state back to the clean, non-searching baseline and
		// invalidate any in-flight request (cancelSearch bumps _searchSeq). This is
		// the single owner of the search-reset invariant — the view must call this
		// rather than mutating searchResults/searchError directly, so future state
		// (e.g. the pagination cursor) stays in one place.
		resetSearch() {
			this.searchQuery = ''
			this.searchResults = []
			this.searchError = false
			this.searchOffset = 0
			this.searchHasMore = false
			this.cancelSearch()
		},

		async runSearch() {
			const seq = ++this._searchSeq
			if (!this.isSearching) {
				this.searchResults = []
				this.searchOffset = 0
				this.searchHasMore = false
				return
			}
			this.searchLoading = true
			this.searchError = false
			try {
				const results = await NoteService.searchNotes(
					this.searchQuery.trim(), PAGE_SIZE, 0, this.sort,
				)
				if (seq !== this._searchSeq) return  // stale response — discard
				this.searchResults = results
				this.searchOffset = results.length
				this.searchHasMore = results.length === PAGE_SIZE
			} catch (e) {
				if (seq === this._searchSeq) this.searchError = this._searchErrorReason(e)
			} finally {
				// Only update searchLoading for the current sequence.
				// Stale responses (seq mismatch) must not reset the loading state,
				// as a newer runSearch() is responsible for its own lifecycle.
				// cancelSearch() sets searchLoading = false synchronously before
				// any stale response can arrive, so the loading state is always
				// correct from the component's perspective.
				if (seq === this._searchSeq) this.searchLoading = false
			}
		},

		// Append the next page of search results, mirroring loadMoreNotes() but on
		// the search list and guarded by the same _searchSeq race token so a stale
		// page (from a search that has since changed) is discarded.
		async loadMoreSearch() {
			if (!this.searchHasMore || this.searchLoading || this.searchLoadingMore || !this.isSearching) return
			const seq = this._searchSeq
			this.searchLoadingMore = true
			// Clear any stale error from a prior failed runSearch() for this
			// sequence, mirroring runSearch(): otherwise a recovered error could
			// keep the "Search failed" empty state shadowing successfully-appended
			// results (the template checks searchError before the results branch).
			this.searchError = false
			try {
				const results = await NoteService.searchNotes(
					this.searchQuery.trim(), PAGE_SIZE, this.searchOffset, this.sort,
				)
				if (seq !== this._searchSeq) return  // stale page — a newer search owns the state
				this.searchResults.push(...results)
				this.searchOffset += results.length
				this.searchHasMore = results.length === PAGE_SIZE
			} catch (e) {
				// Surface the same discriminated reason runSearch() uses so a
				// rate-limited (429) or invalid load-more gets the actionable
				// message, not just the generic toast. Guarded by the seq check so
				// a stale page's failure cannot clobber a newer search's state.
				if (seq === this._searchSeq) this.searchError = this._searchErrorReason(e)
				throw e
			} finally {
				if (seq === this._searchSeq) this.searchLoadingMore = false
			}
		},
	},
})
