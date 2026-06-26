/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/crm_notes/api/notes')

export async function getAllNotes(limit, offset) {
	const params = {}
	if (limit != null) params.limit = limit
	if (offset != null) params.offset = offset
	const { data } = await axios.get(baseUrl, { params })
	return data
}

export async function getNotesByContact(contactUid, limit, offset) {
	const params = {}
	if (limit != null) params.limit = limit
	if (offset != null) params.offset = offset
	const { data } = await axios.get(`${baseUrl}/contact/${encodeURIComponent(contactUid)}`, { params })
	return data
}

export async function createNote(payload) {
	const { data } = await axios.post(baseUrl, payload)
	return data
}

export async function updateNote(id, payload) {
	const { data } = await axios.put(`${baseUrl}/${id}`, payload)
	return data
}

export async function deleteNote(id) {
	const { data } = await axios.delete(`${baseUrl}/${id}`)
	return data
}

export async function addFile(noteId, fileId, filePath) {
	const { data } = await axios.post(`${baseUrl}/${noteId}/files`, { fileId, filePath })
	return data
}

export async function removeFile(noteId, noteFileId) {
	const { data } = await axios.delete(`${baseUrl}/${noteId}/files/${noteFileId}`)
	return data
}
