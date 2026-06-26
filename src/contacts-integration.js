/*
 * SPDX-FileCopyrightText: 2026 CRM Notes Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Contacts app integration — injects a "Notes" panel into the contact detail view.
 *
 * This script is loaded on the Contacts app page via the LoadContactsOcaApiEvent
 * PHP listener. It uses a MutationObserver to detect when a contact detail panel
 * opens and injects a collapsible "CRM Notes" section.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, getLocale } from '@nextcloud/l10n'
import { showError } from '@nextcloud/dialogs'
import { renderMarkdown } from './utils/markdown.js'
import { iconPathForType } from './utils/noteTypeIcon.js'
// Reuse the single, shared contrast implementation rather than carry a hex-only
// duplicate here. The Vue NoteTypeBadge and this non-Vue Contacts panel must
// compute identical foregrounds for the same stored color, and that helper
// resolves both #rgb/#rrggbb and hsl()/hsla() — so a stored hsl() type color
// keeps its AA contrast guarantee on this render surface too, instead of
// falling back to a possibly low-contrast main-text token on a saturated badge.
import { readableTextColor } from './utils/color.js'

const baseUrl = generateUrl('/apps/crm_notes/api')

// Material Design Icons path data (matches vue-material-design-icons used in the
// Vue UI) so the injected, non-Vue panel uses real icons rather than emoji.
const MDI_PATHS = {
	note: 'M14,17H7V15H14M17,13H7V11H17M17,9H7V7H17M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3Z',
	openInNew: 'M14,3V5H17.59L7.76,14.83L9.17,16.24L19,6.41V10H21V3M19,19H5V5H12V3H5C3.89,3 3,3.9 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V12H19V19Z',
	file: 'M13,9H18.5L13,3.5V9M6,2H14L20,8V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V4C4,2.89 4.89,2 6,2M15,18V16H6V18H15M18,14V12H6V14H18Z',
	// MDI pin — mirrors NoteItem.vue's IconPin so a pinned note reads as pinned
	// on the Contacts tab too.
	pin: 'M16,12V4H17V2H7V4H8V12L6,14V16H11.2V22H12.8V16H18V14L16,12Z',
	// MDI chevron-down — the standard NC collapsible-section affordance. Rotated
	// via CSS to point up when the panel is collapsed.
	chevronDown: 'M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z',
}

function mdiIcon(name, size = 16) {
	return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${MDI_PATHS[name]}" /></svg>`
}

// Inline loading spinner matching the NcLoadingIcon affordance the Vue views
// use, themed with NC color tokens. Rendered as a non-Vue island so the
// Contacts tab shows the same spinner cue as AllNotesView/ContactNotesView
// instead of a bare 'Loading…' text node.
function spinnerHtml() {
	return `<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${t('crm_notes', 'Loading…')}</span>
	</span>`
}

/**
 * Validate a CSS color before it is placed in an inline style attribute.
 * Accepts #rgb / #rrggbb and hsl()/hsla(); anything else falls back to a token.
 */
function safeColor(color) {
	if (typeof color !== 'string') return 'var(--color-text-maxcontrast)'
	const c = color.trim()
	if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(c)) return c
	if (/^hsla?\(\s*\d{1,3}\s*,\s*\d{1,3}%\s*,\s*\d{1,3}%\s*(,\s*(0|1|0?\.\d+)\s*)?\)$/.test(c)) return c
	return 'var(--color-text-maxcontrast)'
}

// ---- Helpers ----------------------------------------------------------------

function getContactUid(detailEl) {
	// The contacts app puts the UID in a data attribute or in the URL hash
	// Try data attribute first
	const uid = detailEl.dataset?.contactUid
		|| detailEl.closest('[data-contact-uid]')?.dataset?.contactUid
	if (uid) return uid

	// Fall back to URL hash: /apps/contacts/All%20contacts/contact:UID
	const match = window.location.hash.match(/contact:([^/]+)/)
		|| window.location.pathname.match(/contact:([^/]+)/)
	return match ? decodeURIComponent(match[1]) : null
}

// Page size for the contacts-tab note list. Matches the Vue views' PAGE_SIZE and
// stays at/under the server-side cap so a contact with a very large note history
// loads (and the server enriches/sorts) only one bounded page at a time, with a
// "Show more" control to fetch the next page on demand.
const NOTES_PAGE_SIZE = 50

async function fetchNotes(contactUid, limit = NOTES_PAGE_SIZE, offset = 0) {
	const { data } = await axios.get(
		`${baseUrl}/notes/contact/${encodeURIComponent(contactUid)}`,
		{ params: { limit, offset } },
	)
	return data
}

// The byContact API exposes only noteTypeId on each note, not a resolved
// noteType object. Without the Vue note-types store available here, we fetch the
// note types once and map id -> { name, color } so the badge renders correctly.
let noteTypePromise = null

async function fetchNoteTypeMap() {
	if (!noteTypePromise) {
		noteTypePromise = axios.get(`${baseUrl}/note-types`)
			.then(({ data }) => {
				const map = {}
				for (const type of data) {
					map[type.id] = { name: type.name, color: type.color, icon: type.icon }
				}
				return map
			})
			.catch((e) => {
				// Don't cache a permanently-empty map after a transient failure:
				// reset the module-level promise so a later panel open retries.
				noteTypePromise = null
				throw e
			})
	}
	// Recover from a rejected fetch without breaking the caller's Promise.all.
	return noteTypePromise.catch(() => ({}))
}

// Mirror the locale-aware formatter used in NoteItem.vue so a note's timestamp
// renders identically in the Contacts tab and the CRM Notes app, and honours the
// configured Nextcloud UI language rather than the raw browser locale.
const _dateFormatter = new Intl.DateTimeFormat(getLocale().replace('_', '-'), {
	year: 'numeric', month: 'short', day: 'numeric',
	hour: '2-digit', minute: '2-digit',
})

function formatDate(dateStr) {
	if (!dateStr) return ''
	// API timestamps are ISO-8601 (DateTimeInterface::ATOM); Date parses them
	// directly with their timezone offset — no space-to-'T' munging needed.
	const d = new Date(dateStr)
	return isNaN(d.getTime()) ? '' : _dateFormatter.format(d)
}

// Mirror NoteItem.vue's fileLabel(): prefer the stored name, else the basename
// of the file path, else a translated fallback.
function fileLabel(f) {
	if (f.name) return f.name
	if (f.filePath) return f.filePath.split('/').pop()
	return t('crm_notes', 'Attachment')
}

// ---- Render -----------------------------------------------------------------

// Markdown rendering (marked -> demoteHeadings -> DOMPurify) is the shared
// renderMarkdown() pipeline imported above, identical to NoteItem.vue and the
// modal preview, so the Contacts tab shows formatted markdown with the same
// heading-demotion accessibility guard and sanitisation.

function renderNoteItem(note, noteTypeMap = {}) {
	const div = document.createElement('div')
	div.className = 'crm-contacts-note-item'
	const resolvedType = noteTypeMap[note.noteTypeId] || note.noteType || {}
	const badge = document.createElement('span')
	badge.className = 'crm-contacts-type-badge'
	const bg = safeColor(resolvedType.color)
	badge.style.background = bg
	badge.style.color = readableTextColor(bg)
	// Render the type's chosen icon (if any) next to the name, mirroring the
	// Vue NoteTypeBadge so the icon picker has a visible effect here too. The
	// path data comes from a fixed allow-list, never user input.
	const iconPath = iconPathForType(resolvedType.icon)
	if (iconPath) {
		const iconSpan = document.createElement('span')
		iconSpan.className = 'crm-contacts-type-badge-icon'
		iconSpan.setAttribute('aria-hidden', 'true')
		iconSpan.innerHTML = `<svg viewBox="0 0 24 24" width="13" height="13" fill="currentColor" focusable="false"><path d="${iconPath}" /></svg>`
		badge.appendChild(iconSpan)
	}
	const nameSpan = document.createElement('span')
	nameSpan.textContent = resolvedType.name || ''
	badge.appendChild(nameSpan)

	const header = document.createElement('div')
	header.className = 'crm-contacts-note-header'
	header.appendChild(badge)
	// Heading (not a plain <strong>) so screen-reader users can traverse the
	// notes list heading-by-heading; the class below resets UA heading styles
	// so the visual stays identical to the former bold inline title. Mirrors
	// NoteItem.vue's <h2 class="crm-note-title">.
	const titleEl = document.createElement('h2')
	titleEl.className = 'crm-contacts-note-title'
	titleEl.textContent = note.title || ''
	header.appendChild(titleEl)
	// Pin indicator, mirroring NoteItem.vue so a pinned note reads as pinned on
	// the Contacts tab too. The SVG path comes from the fixed MDI_PATHS map, not
	// user input.
	if (note.isPinned) {
		const pin = document.createElement('span')
		pin.className = 'crm-contacts-pin-indicator'
		pin.setAttribute('role', 'img')
		pin.setAttribute('aria-label', t('crm_notes', 'Pinned'))
		pin.innerHTML = mdiIcon('pin', 16)
		header.appendChild(pin)
	}
	div.appendChild(header)

	if (note.content) {
		const p = document.createElement('div')
		p.className = 'crm-contacts-note-content'
		// DOMPurify-sanitized HTML; safe to assign as innerHTML.
		p.innerHTML = renderMarkdown(note.content)
		div.appendChild(p)
	}

	// File attachments, mirroring NoteItem.vue's file-chip row so an attached
	// note doesn't read as having no files on this surface. Labels are set via
	// textContent (never innerHTML) so a crafted filename can't inject markup.
	if (Array.isArray(note.files) && note.files.length) {
		const filesEl = document.createElement('div')
		filesEl.className = 'crm-contacts-note-files'
		for (const f of note.files) {
			const chip = document.createElement('span')
			chip.className = 'crm-contacts-file-chip'
			const icon = document.createElement('span')
			icon.className = 'crm-contacts-file-chip-icon'
			icon.innerHTML = mdiIcon('file', 12)
			const label = document.createElement('span')
			label.className = 'crm-contacts-file-chip-label'
			label.textContent = fileLabel(f)
			chip.appendChild(icon)
			chip.appendChild(label)
			filesEl.appendChild(chip)
		}
		div.appendChild(filesEl)
	}

	const date = document.createElement('span')
	date.className = 'crm-contacts-note-date'
	date.textContent = formatDate(note.createdAt)
	div.appendChild(date)

	return div
}

async function injectNotesPanel(detailEl) {
	if (detailEl.querySelector('.crm-contacts-notes-panel')) return

	const uid = getContactUid(detailEl)
	if (!uid) return

	// Build the panel
	const panel = document.createElement('div')
	panel.className = 'crm-contacts-notes-panel'
	// Signal the new-tab context change in the accessible name and tooltip
	// (WCAG 3.2.5): the link is icon-only, so neither sighted nor AT users
	// otherwise get a cue that activation spawns a new tab.
	const openLabel = t('crm_notes', 'Open in CRM Notes (opens in a new tab)')
	// The header is a flex row of two independent, properly-roled controls: a real
	// <button> that toggles the body, and a separate <a> link. Avoid nesting the
	// link inside a role=button element (invalid ARIA / ambiguous a11y tree).
	const bodyId = `crm-contacts-notes-body-${Math.random().toString(36).slice(2, 10)}`
	panel.innerHTML = `
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${bodyId}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${mdiIcon('chevronDown', 18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${mdiIcon('note', 18)}</span>
				<span>${t('crm_notes', 'CRM Notes')}</span>
			</button>
			<a class="crm-contacts-open-app"
				href="${generateUrl('/apps/crm_notes')}#contact/${encodeURIComponent(uid)}"
				title="${openLabel}"
				aria-label="${openLabel}"
				target="_blank"
				rel="noopener">${mdiIcon('openInNew', 14)}</a>
		</div>
		<div id="${bodyId}" class="crm-contacts-notes-body">
			${spinnerHtml()}
		</div>
	`
	detailEl.appendChild(panel)

	// Inject CSS once
	if (!document.getElementById('crm-contacts-integration-style')) {
		const style = document.createElement('style')
		style.id = 'crm-contacts-integration-style'
		style.textContent = `
			.crm-contacts-notes-panel {
				margin: calc(var(--default-grid-baseline, 4px) * 3) 0;
				border-top: 1px solid var(--color-border, #ddd);
				padding-top: calc(var(--default-grid-baseline, 4px) * 2);
			}
			.crm-contacts-notes-header {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 4);
			}
			.crm-contacts-notes-toggle {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				flex: 1;
				min-width: 0;
				padding: 0;
				border: none;
				background: none;
				font: inherit;
				font-weight: 600;
				color: inherit;
				cursor: pointer;
				user-select: none;
				text-align: left;
			}
			.crm-contacts-notes-toggle:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-notes-chevron {
				display: inline-flex;
				align-items: center;
				color: var(--color-text-maxcontrast, #888);
				transition: transform 0.15s ease-in-out;
			}
			/* Collapsed: chevron rotated -90deg so the down-chevron points right (closed affordance). */
			.crm-contacts-notes-chevron--collapsed {
				transform: rotate(-90deg);
			}
			.crm-contacts-notes-loading {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
				color: var(--color-text-maxcontrast, #888);
				font-size: var(--font-size-small, 13px);
			}
			.crm-contacts-spinner {
				display: inline-block;
				width: 20px;
				height: 20px;
				border: 2px solid var(--color-border, #ddd);
				border-top-color: var(--color-primary-element, #0082c9);
				border-radius: 50%;
				animation: crm-contacts-spin 0.8s linear infinite;
			}
			@keyframes crm-contacts-spin {
				to { transform: rotate(360deg); }
			}
			@media (prefers-reduced-motion: reduce) {
				.crm-contacts-spinner { animation-duration: 2s; }
				.crm-contacts-notes-chevron { transition: none; }
			}
			.crm-visually-hidden {
				position: absolute;
				width: 1px;
				height: 1px;
				margin: -1px;
				padding: 0;
				overflow: hidden;
				clip: rect(0, 0, 0, 0);
				white-space: nowrap;
				border: 0;
			}
			.crm-contacts-notes-toggle:focus-visible,
			.crm-contacts-open-app:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
			.crm-contacts-open-app {
				margin-left: auto;
				font-size: var(--font-size-small, 13px);
				text-decoration: none;
				color: var(--color-primary-element);
			}
			.crm-contacts-notes-body {
				padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 4);
			}
			.crm-contacts-note-item {
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
				border-bottom: 1px solid var(--color-border, #ddd);
				font-size: var(--font-size-small, 13px);
			}
			.crm-contacts-note-item:last-child { border-bottom: none; }
			.crm-contacts-note-header {
				display: flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1.5);
				margin-bottom: calc(var(--default-grid-baseline, 4px) * 1);
			}
			.crm-contacts-note-title {
				/* Reset UA heading defaults so this <h2> renders like the former
				   bold inline title. */
				margin: 0;
				font-size: inherit;
				font-weight: 600;
				line-height: inherit;
			}
			.crm-contacts-type-badge {
				display: inline-flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
				padding: 1px calc(var(--default-grid-baseline, 4px) * 2);
				border-radius: var(--border-radius-pill, 100px);
				color: var(--color-main-text);
				font-size: var(--font-size-small, 13px);
				font-weight: 600;
				white-space: nowrap;
			}
			.crm-contacts-type-badge-icon {
				display: inline-flex;
				align-items: center;
			}
			.crm-contacts-pin-indicator {
				/* Push the pin to the trailing edge of the header and tint it the
				   primary element colour, matching NoteItem.vue's .crm-pin-indicator. */
				margin-left: auto;
				display: inline-flex;
				align-items: center;
				color: var(--color-primary-element);
			}
			.crm-contacts-note-files {
				display: flex;
				flex-wrap: wrap;
				gap: calc(var(--default-grid-baseline, 4px) * 1.5);
				margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0;
			}
			.crm-contacts-file-chip {
				display: inline-flex;
				align-items: center;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
				background: var(--color-background-dark);
				border-radius: var(--border-radius);
				padding: 2px calc(var(--default-grid-baseline, 4px) * 2);
				font-size: var(--font-size-small, 13px);
				max-width: 100%;
				min-width: 0;
			}
			.crm-contacts-file-chip-icon {
				flex: 0 0 auto;
				display: inline-flex;
				align-items: center;
			}
			.crm-contacts-file-chip-label {
				min-width: 0;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			.crm-contacts-note-content {
				/* Primary note substance — full reading contrast, matching
				   NoteItem.vue's .crm-note-content. --color-text-maxcontrast is
				   reserved for the secondary .crm-contacts-note-date meta line. */
				color: var(--color-main-text);
				margin: calc(var(--default-grid-baseline, 4px) * 0.5) 0 calc(var(--default-grid-baseline, 4px) * 1);
				line-height: 1.5;
				/* Break long unbroken strings (pasted URLs/tokens) so the note
				   body wraps inside the tab instead of overflowing horizontally. */
				overflow-wrap: anywhere;
			}
			.crm-contacts-note-content p { margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5); }
			.crm-contacts-note-content p:last-child { margin-bottom: 0; }
			.crm-contacts-note-content ul,
			.crm-contacts-note-content ol { padding-left: calc(var(--default-grid-baseline, 4px) * 4.5); margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5); }
			.crm-contacts-note-content h4,
			.crm-contacts-note-content h5,
			.crm-contacts-note-content h6 {
				font-weight: 600;
				margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0 calc(var(--default-grid-baseline, 4px) * 0.5);
				color: var(--color-main-text);
			}
			.crm-contacts-note-content code {
				font-family: var(--font-face-monospace, monospace);
				background: var(--color-background-dark);
				padding: 1px calc(var(--default-grid-baseline, 4px) * 1);
				border-radius: var(--border-radius-small, 4px);
			}
			.crm-contacts-note-content pre {
				background: var(--color-background-dark);
				padding: calc(var(--default-grid-baseline, 4px) * 2);
				border-radius: var(--border-radius);
				overflow-x: auto;
			}
			.crm-contacts-note-content a { color: var(--color-primary-element); }
			.crm-contacts-note-date {
				font-size: var(--font-size-small, 13px);
				color: var(--color-text-maxcontrast, #999);
			}
			.crm-contacts-notes-empty {
				color: var(--color-text-maxcontrast, #888);
				font-size: var(--font-size-small, 13px);
				padding: calc(var(--default-grid-baseline, 4px) * 2) 0;
			}
			.crm-contacts-notes-retry {
				display: inline-block;
				margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
				padding: calc(var(--default-grid-baseline, 4px) * 1) calc(var(--default-grid-baseline, 4px) * 3);
				border: 1px solid var(--color-border-dark, #ccc);
				border-radius: var(--border-radius, 4px);
				background: var(--color-main-background);
				color: var(--color-main-text);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				cursor: pointer;
			}
			.crm-contacts-notes-retry:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-notes-retry:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
		`
		document.head.appendChild(style)
	}

	// Toggle collapse via the real <button> — native keyboard activation (Enter /
	// Space) and focus handling come for free, no manual keydown wiring needed.
	const toggleBtn = panel.querySelector('.crm-contacts-notes-toggle')
	const body = panel.querySelector('.crm-contacts-notes-body')
	const chevron = panel.querySelector('.crm-contacts-notes-chevron')
	toggleBtn.addEventListener('click', () => {
		const expanded = toggleBtn.getAttribute('aria-expanded') !== 'false'
		toggleBtn.setAttribute('aria-expanded', expanded ? 'false' : 'true')
		body.style.display = expanded ? 'none' : ''
		// Rotate the chevron in step with the aria-expanded flip so sighted users
		// get a visible open/closed cue, matching NC's collapsible-section pattern.
		if (chevron) chevron.classList.toggle('crm-contacts-notes-chevron--collapsed', expanded)
	})

	// Load notes (with in-panel error + retry, matching the Vue views)
	const bodyEl = panel.querySelector('.crm-contacts-notes-body')
	loadNotesInto(bodyEl, uid)
}

async function loadNotesInto(bodyEl, uid) {
	bodyEl.innerHTML = spinnerHtml()
	try {
		const [notes, noteTypeMap] = await Promise.all([fetchNotes(uid, NOTES_PAGE_SIZE, 0), fetchNoteTypeMap()])
		bodyEl.innerHTML = ''
		if (!notes.length) {
			const empty = document.createElement('p')
			empty.className = 'crm-contacts-notes-empty'
			empty.textContent = t('crm_notes', 'No notes yet')
			bodyEl.appendChild(empty)
		} else {
			notes.forEach(note => bodyEl.appendChild(renderNoteItem(note, noteTypeMap)))
			// A full page implies there may be more — offer an on-demand "Show
			// more" that appends the next page rather than reloading everything.
			if (notes.length === NOTES_PAGE_SIZE) {
				appendShowMore(bodyEl, uid, noteTypeMap, notes.length)
			}
		}
	} catch {
		// Replace the stale "Loading…" with an in-place error + Retry control so
		// the panel never gets stuck on a frozen spinner. The toast alone is easy
		// to miss, and every Vue view in the app offers the same retry affordance.
		bodyEl.innerHTML = ''
		const error = document.createElement('p')
		error.className = 'crm-contacts-notes-empty'
		error.textContent = t('crm_notes', 'Could not load notes.')
		bodyEl.appendChild(error)
		const retry = document.createElement('button')
		retry.type = 'button'
		retry.className = 'crm-contacts-notes-retry'
		retry.textContent = t('crm_notes', 'Retry')
		retry.addEventListener('click', () => loadNotesInto(bodyEl, uid))
		bodyEl.appendChild(retry)
		showError(t('crm_notes', 'Failed to load CRM notes.'))
	}
}

// Append a "Show more" button that fetches and appends the next page of notes
// in place. Reuses the retry-button styling so no new CSS is needed. Tracks the
// running offset via a closure so repeated clicks keep advancing the window.
function appendShowMore(bodyEl, uid, noteTypeMap, loadedCount) {
	const more = document.createElement('button')
	more.type = 'button'
	more.className = 'crm-contacts-notes-retry'
	more.textContent = t('crm_notes', 'Show more')
	let offset = loadedCount
	more.addEventListener('click', async () => {
		more.disabled = true
		more.textContent = t('crm_notes', 'Loading…')
		try {
			const next = await fetchNotes(uid, NOTES_PAGE_SIZE, offset)
			next.forEach(note => bodyEl.insertBefore(renderNoteItem(note, noteTypeMap), more))
			offset += next.length
			if (next.length === NOTES_PAGE_SIZE) {
				more.disabled = false
				more.textContent = t('crm_notes', 'Show more')
			} else {
				more.remove()
			}
		} catch {
			more.disabled = false
			more.textContent = t('crm_notes', 'Show more')
			showError(t('crm_notes', 'Failed to load more notes.'))
		}
	})
	bodyEl.appendChild(more)
}

// ---- Observe ----------------------------------------------------------------

// The detail element we last injected a panel into. While it is still in the
// DOM and still carries our panel, the steady state needs no DOM queries at all.
let lastInjectedDetailEl = null

function findAndInject() {
	// Steady-state fast path: if the detail container we already injected into is
	// still connected and still carries our panel, there is nothing to do and we
	// perform zero querySelector calls — this is the common case while the
	// Contacts SPA mutates (typing, scrolling) without swapping the open contact.
	if (lastInjectedDetailEl
		&& lastInjectedDetailEl.isConnected
		&& lastInjectedDetailEl.querySelector('.crm-contacts-notes-panel')) {
		return
	}

	// The contacts app detail panel can have different selectors depending on version
	const selectors = [
		'.contact-details',
		'.contact__details',
		'[class*="contact-detail"]',
		'.app-content-detail',
	]
	for (const sel of selectors) {
		const el = document.querySelector(sel)
		if (el) {
			injectNotesPanel(el)
			lastInjectedDetailEl = el
			return
		}
	}
	// No detail container present (e.g. list view) — drop the stale reference so
	// the next detail open is detected.
	lastInjectedDetailEl = null
}

// The Contacts app is a Vue SPA that mutates document.body constantly (typing,
// scrolling lists, opening panels). Running findAndInject() — four
// document.querySelector() calls — on every raw mutation is a steady, avoidable
// CPU cost layered onto another team's app, and injectNotesPanel() itself
// appends nodes that re-trigger the observer. Coalesce bursts of mutations into
// a single rAF-deferred run so the work happens at most once per frame, and
// short-circuit immediately when a panel already exists for the current detail
// container so the common steady-state case does no DOM queries at all.
let injectScheduled = false

function scheduleInject() {
	if (injectScheduled) return
	injectScheduled = true
	requestAnimationFrame(() => {
		injectScheduled = false
		findAndInject()
	})
}

const observer = new MutationObserver(() => {
	scheduleInject()
})

document.addEventListener('DOMContentLoaded', () => {
	observer.observe(document.body, { childList: true, subtree: true })
	findAndInject()
})

// Also handle popstate/hashchange for SPA navigation. A new contact route may
// not have rendered its detail panel yet, so defer one coalesced attempt.
window.addEventListener('hashchange', () => {
	setTimeout(scheduleInject, 200)
})
window.addEventListener('popstate', () => {
	setTimeout(scheduleInject, 200)
})
