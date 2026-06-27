/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { nextTick } from 'vue'

/**
 * Walk up from an element to the nearest ancestor that actually scrolls
 * vertically (overflow auto/scroll and a scrollable content height). The
 * note lists live inside NcAppContent, whose inner content area is the real
 * scroll container — not the view's own root div — so capturing scrollTop on
 * the view element alone would read 0 and fail to restore position.
 *
 * @param {HTMLElement|null} el starting element (usually the view root)
 * @return {HTMLElement|null} the scroll container, or null if none found
 */
export function findScrollContainer(el) {
	let node = el?.parentElement || null
	while (node && node !== document.body && node !== document.documentElement) {
		const style = window.getComputedStyle(node)
		const oy = style.overflowY
		if ((oy === 'auto' || oy === 'scroll') && node.scrollHeight > node.clientHeight) {
			return node
		}
		node = node.parentElement
	}
	// Fall back to the documentElement so a page-level scroll is still handled.
	return document.scrollingElement || document.documentElement
}

/**
 * Run an async mutation (e.g. appending the next page of notes) while keeping
 * the scroll container visually pinned where the user was. We restore the
 * captured scrollTop after Vue has flushed the appended rows to the DOM
 * (nextTick), so the new items appear below and the viewport does not jump.
 *
 * Restoring the absolute scrollTop is correct for an append-below operation:
 * existing rows keep their position and offset, so the previous scrollTop still
 * points at the same content.
 *
 * @param {HTMLElement|null} rootEl the view's root element
 * @param {() => Promise<unknown>} mutate the async append operation
 * @return {Promise<unknown>} the result of mutate()
 */
export async function withScrollPreserved(rootEl, mutate) {
	const container = findScrollContainer(rootEl)
	const top = container ? container.scrollTop : 0
	const result = await mutate()
	await nextTick()
	if (container) {
		container.scrollTop = top
	}
	return result
}
