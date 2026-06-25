/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { marked } from 'marked'
import DOMPurify from 'dompurify'

// Harden any anchor that carries a target (e.g. a note body containing raw HTML
// like `<a target="_blank" href="…">`). marked-generated links never set target,
// but raw HTML in the source can, and a target without rel="noopener" enables
// reverse tabnabbing of the opener. This single hook on the shared pipeline
// guarantees every render surface (NoteItem, the modal preview, the Contacts
// tab) gets the same protection. Registered once at module load.
DOMPurify.addHook('afterSanitizeAttributes', (node) => {
	if (node.tagName === 'A' && node.hasAttribute('target')) {
		node.setAttribute('target', '_blank')
		node.setAttribute('rel', 'noopener noreferrer')
	}
})

/**
 * Demote user heading levels by three so a "# Title" written in a note body
 * cannot inject an <h1>/<h2> that corrupts the surrounding document heading
 * outline for screen-reader navigation. The largest user heading becomes an
 * <h4>, keeping it below the surrounding structure while preserving relative
 * hierarchy. Runs before sanitisation.
 *
 * @param {string} html parsed (but not yet sanitised) HTML
 * @return {string} HTML with heading levels demoted
 */
export function demoteHeadings(html) {
	return html.replace(/<(\/?)h([1-6])\b/gi, (match, slash, level) => {
		const newLevel = Math.min(6, Number(level) + 3)
		return `<${slash}h${newLevel}`
	})
}

/**
 * Render a markdown note body to sanitised HTML through the single pipeline
 * shared by every render path (NoteItem, the modal preview, and the Contacts
 * tab): marked -> demoteHeadings -> DOMPurify. Keeping this in one place means
 * the accessibility heading guard and sanitisation can never drift between
 * render surfaces.
 *
 * @param {string} content markdown source
 * @return {string} sanitised HTML
 */
export function renderMarkdown(content) {
	if (!content) return ''
	return DOMPurify.sanitize(demoteHeadings(marked.parse(content, { breaks: true })))
}
