/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/touchpoint/api')

export async function searchContacts(term = '') {
	const { data } = await axios.get(`${baseUrl}/contacts`, { params: { term } })
	return data
}
