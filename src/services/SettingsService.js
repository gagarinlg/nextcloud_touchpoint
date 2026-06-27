/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/touchpoint/api/settings')

export async function getSettings() {
	const { data } = await axios.get(baseUrl)
	return data
}

export async function saveSettings(payload) {
	const { data } = await axios.post(baseUrl, payload)
	return data
}

export async function searchPrincipals(q) {
	const { data } = await axios.get(`${baseUrl}/principals`, { params: { q } })
	return data
}
