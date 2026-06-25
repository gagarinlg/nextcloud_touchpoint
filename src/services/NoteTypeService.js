/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/crm_notes/api/note-types')

export async function getAllNoteTypes() {
	const { data } = await axios.get(baseUrl)
	return data
}

export async function createNoteType(payload) {
	const { data } = await axios.post(baseUrl, payload)
	return data
}

export async function updateNoteType(id, payload) {
	const { data } = await axios.put(`${baseUrl}/${id}`, payload)
	return data
}

export async function deleteNoteType(id) {
	const { data } = await axios.delete(`${baseUrl}/${id}`)
	return data
}

export async function getNoteTypeUsage(id) {
	const { data } = await axios.get(`${baseUrl}/${id}/usage`)
	return data
}
