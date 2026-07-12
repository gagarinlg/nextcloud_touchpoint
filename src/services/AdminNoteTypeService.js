/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/touchpoint/api/admin/note-types')

export async function getAllGlobalNoteTypes() {
	const { data } = await axios.get(baseUrl)
	return data
}

export async function getGlobalNoteTypeUsage(id) {
	const { data } = await axios.get(`${baseUrl}/${id}/usage`)
	return data
}

export async function createGlobalNoteType(payload) {
	const { data } = await axios.post(baseUrl, payload)
	return data
}

export async function updateGlobalNoteType(id, payload) {
	const { data } = await axios.put(`${baseUrl}/${id}`, payload)
	return data
}

export async function deleteGlobalNoteType(id) {
	const { data } = await axios.delete(`${baseUrl}/${id}`)
	return data
}
