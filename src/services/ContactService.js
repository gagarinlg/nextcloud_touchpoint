/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/crm_notes/api')

export async function searchContacts(term = '') {
	const { data } = await axios.get(`${baseUrl}/contacts`, { params: { term } })
	return data
}
