/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
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
	}),
	actions: {
		async loadAll() {
			this.loading = true
			this.allNotesError = false
			this.allNotesOffset = 0
			this.allNotesHasMore = false
			try {
				const notes = await NoteService.getAllNotes(PAGE_SIZE, 0)
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
				const notes = await NoteService.getAllNotes(PAGE_SIZE, this.allNotesOffset)
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
				const notes = await NoteService.getNotesByContact(uid, PAGE_SIZE, 0)
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
	},
})
