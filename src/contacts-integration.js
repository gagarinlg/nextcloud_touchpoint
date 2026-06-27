/*
 * SPDX-FileCopyrightText: 2026 Touchpoint Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Contacts app integration — injects a "Notes" panel into the contact detail view.
 *
 * This script is loaded on the Contacts app page via the LoadContactsOcaApiEvent
 * PHP listener. It uses a MutationObserver to detect when a contact detail panel
 * opens and injects a collapsible "Touchpoint" section.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { translate as t, getLocale } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { renderMarkdown } from './utils/markdown.js'
import { iconPathForType } from './utils/noteTypeIcon.js'
// Reuse the single, shared contrast implementation rather than carry a hex-only
// duplicate here. The Vue NoteTypeBadge and this non-Vue Contacts panel must
// compute identical foregrounds for the same stored color, and that helper
// resolves both #rgb/#rrggbb and hsl()/hsla(). It picks black or white by the
// actual WCAG contrast ratio (whichever scores higher against the background)
// and falls back to the NC main-text token only when neither pure foreground
// reaches AA — so this surface gets the same best-effort contrast as the Vue
// badge for the same stored color, including saturated hsl() values.
import { readableTextColor } from './utils/color.js'

const baseUrl = generateUrl('/apps/touchpoint/api')

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
	plus: 'M19,13H13V19H11V13H5V11H11V5H13V11H19V13Z',
}

function mdiIcon(name, size = 16) {
	return `<svg viewBox="0 0 24 24" width="${size}" height="${size}" fill="currentColor" aria-hidden="true" focusable="false"><path d="${MDI_PATHS[name]}" /></svg>`
}

// Escape a string for safe interpolation into HTML markup (text content OR a
// double-quoted attribute value). Applied to every translated string dropped
// into the panel's innerHTML template: a translation/copy-edit containing a
// quote, <, > or & must not break out of an attribute or corrupt the markup.
// Dynamic data on this surface already goes through textContent/DOMPurify; this
// brings the static chrome up to the same standard.
function escapeHtml(value) {
	return String(value)
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;')
}

// Inline loading spinner matching the NcLoadingIcon affordance the Vue views
// use, themed with NC color tokens. Rendered as a non-Vue island so the
// Contacts tab shows the same spinner cue as AllNotesView/ContactNotesView
// instead of a bare 'Loading…' text node.
function spinnerHtml() {
	return `<span class="crm-contacts-notes-loading" role="status">
		<span class="crm-contacts-spinner" aria-hidden="true"></span>
		<span class="crm-visually-hidden">${escapeHtml(t('touchpoint', 'Loading…'))}</span>
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

// Decode a possibly-URL-encoded, standard- or URL-safe base64 string to a
// proper UTF-8 string, or null. atob() yields a *binary* (Latin-1) string, so a
// UID with non-ASCII characters would be byte-corrupted if returned as-is; we
// run the byte string through TextDecoder so multibyte UIDs survive intact.
const _utf8Decoder = new TextDecoder('utf-8', { fatal: true })

function decodeBase64Maybe(value) {
	try {
		const decoded = decodeURIComponent(value)
		let binary
		try {
			binary = atob(decoded)
		} catch {
			// URL-safe base64 variant (- and _ instead of + and /).
			binary = atob(decoded.replace(/-/g, '+').replace(/_/g, '/'))
		}
		// Re-interpret the binary string's bytes as UTF-8. fatal:true makes an
		// invalid byte sequence throw rather than yield replacement characters,
		// so a non-base64 path segment falls through to null instead of guessing.
		const bytes = Uint8Array.from(binary, (ch) => ch.charCodeAt(0))
		return _utf8Decoder.decode(bytes)
	} catch {
		return null
	}
}

function getContactUid(detailEl) {
	// 1) Some Contacts versions expose the UID directly as a data attribute.
	//    This is the authoritative, separator-free source — prefer it whenever
	//    present so a UID containing a literal '~' is never mis-split below.
	const uid = detailEl.dataset?.contactUid
		|| detailEl.closest('[data-contact-uid]')?.dataset?.contactUid
	if (uid) return uid

	// 2) Contacts 8.x routes a contact as
	//    /apps/contacts/<group>/<base64(`${uid}~${addressbookUri}`)>
	//    The last path segment base64-decodes to "uid~addressbook". The address
	//    book uri is appended after the final '~', so the UID is everything
	//    before it. vCard UIDs are effectively always '~'-free, but a UID that
	//    did contain '~' cannot be unambiguously recovered from this packed
	//    segment — so if the decoded value has no '~' at all it is malformed and
	//    we bail (return null) rather than emit the wrong contact's notes.
	const segment = window.location.pathname.split('/').filter(Boolean).pop()
	if (segment) {
		const decoded = decodeBase64Maybe(segment)
		if (decoded !== null && decoded.includes('~')) {
			const candidate = decoded.substring(0, decoded.lastIndexOf('~'))
			if (candidate) return candidate
		}
	}

	// 3) Legacy hash/path format: .../contact:UID
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
// renders identically in the Contacts tab and the Touchpoint app, and honours the
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
	return t('touchpoint', 'Attachment')
}

// ---- Render -----------------------------------------------------------------

// Markdown rendering (marked -> demoteHeadings -> DOMPurify) is the shared
// renderMarkdown() pipeline imported above, identical to NoteItem.vue and the
// modal preview, so the Contacts tab shows formatted markdown with the same
// heading-demotion accessibility guard (largest user heading becomes an <h3>,
// under the <h2> note title) and sanitisation.

function renderNoteItem(note, noteTypeMap = {}) {
	const div = document.createElement('div')
	div.className = 'crm-contacts-note-item'
	// Stamp the note id so paginated appends can dedupe against rows already on
	// screen (e.g. a note added inline shifts the server's offset-based page
	// boundary, which would otherwise re-show an already-visible note).
	if (note.id != null) div.dataset.noteId = String(note.id)
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
		pin.setAttribute('aria-label', t('touchpoint', 'Pinned'))
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
	const uid = getContactUid(detailEl)
	if (!uid) return

	// If a panel already exists, only keep it when it was built for the contact
	// currently on screen. The Contacts SPA frequently reuses the same detail
	// container element and just swaps the inner contact when navigating A -> B,
	// which would otherwise leave contact A's notes (and its "Open in Touchpoint"
	// link) showing for contact B. On a UID mismatch, tear the stale panel down so
	// it is rebuilt below for the new contact.
	const existing = detailEl.querySelector('.crm-contacts-notes-panel')
	if (existing) {
		if (existing.dataset.crmContactUid === uid) return
		existing.remove()
	}

	// Build the panel
	const panel = document.createElement('div')
	panel.className = 'crm-contacts-notes-panel'
	// Record which contact this panel was built for so findAndInject() can detect
	// an in-place A -> B contact switch and rebuild instead of showing stale notes.
	panel.dataset.crmContactUid = uid
	// Signal the new-tab context change in the accessible name and tooltip
	// (WCAG 3.2.5): the link is icon-only, so neither sighted nor AT users
	// otherwise get a cue that activation spawns a new tab.
	const openLabel = t('touchpoint', 'Open in Touchpoint (opens in a new tab)')
	const addLabel = t('touchpoint', 'Add note')
	// The header is a flex row of two independent, properly-roled controls: a real
	// <button> that toggles the body, and a separate <a> link. Avoid nesting the
	// link inside a role=button element (invalid ARIA / ambiguous a11y tree).
	const suffix = Math.random().toString(36).slice(2, 10)
	const bodyId = `crm-contacts-notes-body-${suffix}`
	const addFormId = `crm-contacts-addform-${suffix}`
	panel.innerHTML = `
		<div class="crm-contacts-notes-header">
			<button type="button" class="crm-contacts-notes-toggle" aria-expanded="true" aria-controls="${bodyId}">
				<span class="crm-contacts-notes-chevron" aria-hidden="true">${mdiIcon('chevronDown', 18)}</span>
				<span class="crm-contacts-notes-icon" aria-hidden="true">${mdiIcon('note', 18)}</span>
				<span>${escapeHtml(t('touchpoint', 'Touchpoint'))}</span>
			</button>
			<button type="button" class="crm-contacts-notes-add" title="${escapeHtml(addLabel)}" aria-label="${escapeHtml(addLabel)}" aria-expanded="false" aria-controls="${addFormId}">${mdiIcon('plus', 16)}</button>
			<a class="crm-contacts-open-app"
				href="${generateUrl('/apps/touchpoint')}#contact/${encodeURIComponent(uid)}"
				title="${escapeHtml(openLabel)}"
				aria-label="${escapeHtml(openLabel)}"
				target="_blank"
				rel="noopener">${mdiIcon('openInNew', 14)}</a>
		</div>
		<form id="${addFormId}" class="crm-contacts-notes-addform" hidden>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${addFormId}-title">${escapeHtml(t('touchpoint', 'Title'))}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<input id="${addFormId}-title" type="text" class="crm-contacts-addform-title" maxlength="255" required placeholder="${escapeHtml(t('touchpoint', 'Note title'))}" />
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${addFormId}-type">${escapeHtml(t('touchpoint', 'Type'))}<span class="crm-contacts-addform-required" aria-hidden="true">*</span></label>
				<!-- Native <select> is an intentional exception to the "prefer
				     NcSelect" rule: this is a non-Vue DOM island injected into the
				     Contacts app, so an NcSelect cannot be hosted here without
				     standing up a whole Vue runtime for one control. It is fully
				     accessible (visible <label for>, required, keyboard-operable)
				     and themed with NC tokens to approximate the design system. -->
				<select id="${addFormId}-type" class="crm-contacts-addform-type" required></select>
			</div>
			<div class="crm-contacts-addform-row">
				<label class="crm-contacts-addform-label" for="${addFormId}-content">${escapeHtml(t('touchpoint', 'Content'))}</label>
				<textarea id="${addFormId}-content" class="crm-contacts-addform-content" rows="3" placeholder="${escapeHtml(t('touchpoint', 'Write a note…'))}"></textarea>
			</div>
			<p class="crm-contacts-addform-hint" role="status" aria-live="polite" hidden></p>
			<div class="crm-contacts-addform-actions">
				<button type="button" class="crm-contacts-addform-cancel">${escapeHtml(t('touchpoint', 'Cancel'))}</button>
				<button type="submit" class="crm-contacts-addform-save">${escapeHtml(t('touchpoint', 'Save'))}</button>
			</div>
		</form>
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
			.crm-contacts-note-content h3,
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
			.crm-contacts-notes-add {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				border: none;
				background: none;
				color: var(--color-text-maxcontrast, #888);
				cursor: pointer;
				padding: calc(var(--default-grid-baseline, 4px) * 1);
				border-radius: var(--border-radius, 4px);
			}
			.crm-contacts-notes-add:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
				color: var(--color-main-text);
			}
			.crm-contacts-notes-add:focus-visible {
				outline: 2px solid var(--color-primary-element);
				outline-offset: 1px;
			}
			.crm-contacts-notes-addform {
				display: flex;
				flex-direction: column;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
				padding: 0 calc(var(--default-grid-baseline, 4px) * 4) calc(var(--default-grid-baseline, 4px) * 2);
			}
			/* Stacked label + control, mirroring NoteModal.vue's .crm-form-row so the
			   inline form reads as part of the design system: a visible bold caption
			   above each control (placeholders alone fail WCAG 3.3.2). */
			.crm-contacts-addform-row {
				display: flex;
				flex-direction: column;
				gap: calc(var(--default-grid-baseline, 4px) * 1);
			}
			.crm-contacts-addform-label {
				font-weight: 600;
				font-size: var(--font-size-small, 13px);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-required {
				color: var(--color-error);
				margin-inline-start: 2px;
			}
			/* Missing-required-fields hint, matching NoteModal.vue's .crm-save-hint. */
			.crm-contacts-addform-hint {
				margin: 0;
				font-size: var(--font-size-small, 13px);
				color: var(--color-text-maxcontrast);
				text-align: end;
			}
			/* Mirror NoteModal.vue's .crm-markdown-editor: a 1px NC-token border,
			   NC radius and NC background/text tokens, so these native controls
			   read as part of the design system rather than raw UA widgets. */
			.crm-contacts-addform-title,
			.crm-contacts-addform-type,
			.crm-contacts-addform-content {
				width: 100%;
				box-sizing: border-box;
				border: 1px solid var(--color-border-dark, #ccc);
				border-radius: var(--border-radius, 4px);
				padding: calc(var(--default-grid-baseline, 4px) * 2);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				background: var(--color-main-background);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-title:hover,
			.crm-contacts-addform-type:hover,
			.crm-contacts-addform-content:hover {
				border-color: var(--color-primary-element);
			}
			.crm-contacts-addform-title:focus,
			.crm-contacts-addform-title:focus-visible,
			.crm-contacts-addform-type:focus,
			.crm-contacts-addform-type:focus-visible,
			.crm-contacts-addform-content:focus,
			.crm-contacts-addform-content:focus-visible {
				outline: none;
				border-color: var(--color-primary-element);
				box-shadow: 0 0 0 2px var(--color-primary-element);
			}
			.crm-contacts-addform-content {
				resize: vertical;
				min-height: 56px;
				font-family: var(--font-face-monospace, monospace);
			}
			.crm-contacts-addform-actions {
				display: flex;
				justify-content: flex-end;
				gap: calc(var(--default-grid-baseline, 4px) * 2);
			}
			/* Themed action buttons matching the NcButton styling used everywhere
			   else: Cancel is a neutral/secondary control, Save is the primary
			   action tinted with --color-primary-element. */
			.crm-contacts-addform-cancel,
			.crm-contacts-addform-save {
				border: none;
				border-radius: var(--border-radius-element, var(--border-radius, 4px));
				padding: calc(var(--default-grid-baseline, 4px) * 1.5) calc(var(--default-grid-baseline, 4px) * 3);
				font: inherit;
				font-size: var(--font-size-small, 13px);
				font-weight: 600;
				cursor: pointer;
			}
			.crm-contacts-addform-cancel {
				background: var(--color-background-dark);
				color: var(--color-main-text);
			}
			.crm-contacts-addform-cancel:hover {
				background: var(--color-background-hover, rgba(0,0,0,.04));
			}
			.crm-contacts-addform-save {
				background: var(--color-primary-element);
				color: var(--color-primary-element-text);
			}
			.crm-contacts-addform-save:hover {
				background: var(--color-primary-element-hover, var(--color-primary-element));
			}
			.crm-contacts-addform-save:disabled {
				opacity: 0.5;
				cursor: default;
			}
			.crm-contacts-addform-cancel:focus-visible,
			.crm-contacts-addform-save:focus-visible {
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
	setupAddNote(panel, bodyEl, uid)
	loadNotesInto(bodyEl, uid)
}

// Inline "add note" form: create a note for the open contact without leaving the
// Contacts app. Posts to the same API the Vue app uses and prepends the created
// note to the list on success.
function setupAddNote(panel, bodyEl, uid) {
	const addBtn = panel.querySelector('.crm-contacts-notes-add')
	const form = panel.querySelector('.crm-contacts-notes-addform')
	if (!addBtn || !form) return
	const titleEl = form.querySelector('.crm-contacts-addform-title')
	const typeEl = form.querySelector('.crm-contacts-addform-type')
	const contentEl = form.querySelector('.crm-contacts-addform-content')
	const hintEl = form.querySelector('.crm-contacts-addform-hint')
	const cancelBtn = form.querySelector('.crm-contacts-addform-cancel')
	const saveBtn = form.querySelector('.crm-contacts-addform-save')
	const toggleBtn = panel.querySelector('.crm-contacts-notes-toggle')
	let typeMap = {}
	// Until the async type list resolves the <select> is empty, so there is
	// nothing valid to submit; keep Save disabled (mirroring NoteModal's canSave
	// guard) and show why, then enable + auto-select once options arrive. If the
	// instance has zero note types, Save stays disabled with a "create one first"
	// affordance instead of offering an empty required control.
	saveBtn.disabled = true
	// Populate the note-type <select> from the same source the badges use.
	fetchNoteTypeMap().then((map) => {
		typeMap = map
		typeEl.innerHTML = ''
		for (const [id, type] of Object.entries(map)) {
			const opt = document.createElement('option')
			opt.value = id
			opt.textContent = type.name
			typeEl.appendChild(opt)
		}
		const ids = Object.keys(map)
		if (ids.length) {
			// Auto-select the first type so an immediate submit has a valid value,
			// matching NoteModal.vue's onMounted default-type selection.
			typeEl.value = ids[0]
			saveBtn.disabled = false
			// Clear any prior "no note types" guidance now that picks exist; a
			// transient missing-fields hint (if shown) is re-evaluated on next input.
			if (hintEl.dataset.crmNoTypes === '1') {
				delete hintEl.dataset.crmNoTypes
				hintEl.hidden = true
				hintEl.textContent = ''
			}
		} else {
			// No note types exist yet: there is nothing to pick, so guide the user
			// to create one rather than letting them submit an empty required field.
			saveBtn.disabled = true
			hintEl.dataset.crmNoTypes = '1'
			hintEl.textContent = t('touchpoint', 'Create a note type first.')
			hintEl.hidden = false
		}
	}).catch(() => {
		// Could not load types — leave Save disabled and explain rather than
		// presenting an empty required control that can never submit.
		saveBtn.disabled = true
		hintEl.dataset.crmNoTypes = '1'
		hintEl.textContent = t('touchpoint', 'Could not load note types.')
		hintEl.hidden = false
	})
	// Surface the still-missing required fields in form order, mirroring
	// NoteModal.vue's missingFieldsHint, instead of silently focusing the title.
	function showMissingFieldsHint() {
		const missing = []
		if (!titleEl.value.trim()) missing.push(t('touchpoint', 'Title'))
		if (!typeEl.value) missing.push(t('touchpoint', 'Type'))
		if (!missing.length) {
			hintEl.hidden = true
			hintEl.textContent = ''
			return
		}
		hintEl.textContent = t('touchpoint', 'Required: {fields}', { fields: missing.join(', ') })
		hintEl.hidden = false
	}
	function closeForm() {
		form.hidden = true
		addBtn.setAttribute('aria-expanded', 'false')
		form.reset()
		// Return focus to the trigger before/after hiding the form. Hiding the
		// currently-focused element (Cancel button, or a field after a successful
		// Save that calls closeForm()) would otherwise strand keyboard/AT focus on
		// a now-hidden node, sending the next Tab to the document top. Mirrors the
		// focus restoration the Vue NoteModal performs.
		addBtn.focus()
		// Keep the persistent "no note types" guidance (and the disabled Save) on
		// reopen; only clear a transient missing-fields hint.
		if (hintEl.dataset.crmNoTypes === '1') return
		hintEl.hidden = true
		hintEl.textContent = ''
	}
	// Allow Escape to dismiss the inline form (returning focus to the trigger via
	// closeForm), matching the dismiss affordance of the Vue modal.
	form.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' && !form.hidden) {
			e.preventDefault()
			closeForm()
		}
	})
	addBtn.addEventListener('click', () => {
		const willOpen = form.hidden
		form.hidden = !willOpen
		addBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false')
		if (willOpen) {
			if (toggleBtn && toggleBtn.getAttribute('aria-expanded') === 'false') toggleBtn.click()
			titleEl.focus()
		}
	})
	cancelBtn.addEventListener('click', closeForm)
	// Once a hint is showing, re-validate as the user fills the fields in so it
	// clears the moment the requirements are met.
	const refreshHintIfShown = () => { if (!hintEl.hidden) showMissingFieldsHint() }
	titleEl.addEventListener('input', refreshHintIfShown)
	typeEl.addEventListener('change', refreshHintIfShown)
	form.addEventListener('submit', async (e) => {
		e.preventDefault()
		const title = titleEl.value.trim()
		if (!title || !typeEl.value) {
			showMissingFieldsHint()
			// Move focus to the first missing required field so keyboard users
			// land on what needs filling in.
			if (!title) titleEl.focus()
			else typeEl.focus()
			return
		}
		hintEl.hidden = true
		hintEl.textContent = ''
		saveBtn.disabled = true
		const prevLabel = saveBtn.textContent
		saveBtn.textContent = t('touchpoint', 'Saving\u2026')
		try {
			const { data: note } = await axios.post(`${baseUrl}/notes`, {
				contactUid: uid,
				noteTypeId: Number(typeEl.value),
				title,
				content: contentEl.value || null,
			})
			const empty = bodyEl.querySelector('.crm-contacts-notes-empty')
			if (empty) empty.remove()
			bodyEl.insertBefore(renderNoteItem(note, typeMap), bodyEl.firstChild)
			closeForm()
			showSuccess(t('touchpoint', 'Note added.'))
		} catch (err) {
			showError(t('touchpoint', 'Failed to add note.'))
		} finally {
			saveBtn.disabled = false
			saveBtn.textContent = prevLabel
		}
	})
}

async function loadNotesInto(bodyEl, uid) {
	bodyEl.innerHTML = spinnerHtml()
	try {
		const [notes, noteTypeMap] = await Promise.all([fetchNotes(uid, NOTES_PAGE_SIZE, 0), fetchNoteTypeMap()])
		bodyEl.innerHTML = ''
		if (!notes.length) {
			const empty = document.createElement('p')
			empty.className = 'crm-contacts-notes-empty'
			empty.textContent = t('touchpoint', 'No notes yet')
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
		error.textContent = t('touchpoint', 'Could not load notes.')
		bodyEl.appendChild(error)
		const retry = document.createElement('button')
		retry.type = 'button'
		retry.className = 'crm-contacts-notes-retry'
		retry.textContent = t('touchpoint', 'Retry')
		retry.addEventListener('click', () => loadNotesInto(bodyEl, uid))
		bodyEl.appendChild(retry)
		showError(t('touchpoint', 'Failed to load CRM notes.'))
	}
}

// Append a "Show more" button that fetches and appends the next page of notes
// in place. Reuses the retry-button styling so no new CSS is needed. Tracks the
// running offset via a closure so repeated clicks keep advancing the window.
function appendShowMore(bodyEl, uid, noteTypeMap, loadedCount) {
	const more = document.createElement('button')
	more.type = 'button'
	more.className = 'crm-contacts-notes-retry'
	more.textContent = t('touchpoint', 'Show more')
	let offset = loadedCount
	more.addEventListener('click', async () => {
		more.disabled = true
		more.textContent = t('touchpoint', 'Loading…')
		try {
			const next = await fetchNotes(uid, NOTES_PAGE_SIZE, offset)
			// Advance the offset by the full fetched page regardless of dedupe, so
			// the window keeps moving and we don't re-request the same slice.
			offset += next.length
			// Skip any note already rendered (e.g. one added inline after the first
			// page loaded, which shifts the server's offset-based page boundary).
			next.forEach(note => {
				if (note.id != null && bodyEl.querySelector(`.crm-contacts-note-item[data-note-id="${note.id}"]`)) return
				bodyEl.insertBefore(renderNoteItem(note, noteTypeMap), more)
			})
			if (next.length === NOTES_PAGE_SIZE) {
				more.disabled = false
				more.textContent = t('touchpoint', 'Show more')
			} else {
				more.remove()
			}
		} catch {
			more.disabled = false
			more.textContent = t('touchpoint', 'Show more')
			showError(t('touchpoint', 'Failed to load more notes.'))
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
	// still connected and still carries our panel for the contact currently on
	// screen, there is nothing to do and we perform almost no DOM work — this is
	// the common case while the Contacts SPA mutates (typing, scrolling) without
	// swapping the open contact.
	//
	// Crucially we re-consult getContactUid() against the stamped panel UID: the
	// Contacts SPA commonly keeps the same detail container mounted and only swaps
	// the inner contact's props when navigating A -> B, so a panel can still be
	// present while now belonging to the wrong contact. In that case we must fall
	// through to injectNotesPanel(), which rebuilds the panel for the new UID.
	if (lastInjectedDetailEl && lastInjectedDetailEl.isConnected) {
		const panel = lastInjectedDetailEl.querySelector('.crm-contacts-notes-panel')
		if (panel && panel.dataset.crmContactUid === getContactUid(lastInjectedDetailEl)) {
			return
		}
	}

	// The contacts app detail panel can have different selectors depending on version
	const selectors = [
		'.contact-details-wrapper',
		'.contact-details',
		'.contact__details',
		'[class*="contact-detail"]',
		'.app-content-detail',
	]
	for (const sel of selectors) {
		const el = document.querySelector(sel)
		if (el) {
			// injectNotesPanel() is idempotent for the current contact and rebuilds
			// on a UID mismatch, so this also covers the in-place A -> B switch.
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

function startObserving() {
	observer.observe(document.body, { childList: true, subtree: true })
	findAndInject()
}
// This script is loaded as a (deferred) module, which can execute after
// DOMContentLoaded has already fired — in that case the event would never call
// us. Start immediately when the document is already parsed; otherwise wait.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', startObserving)
} else {
	startObserving()
}

// Also handle popstate/hashchange for SPA navigation. A new contact route may
// not have rendered its detail panel yet, so defer one coalesced attempt.
window.addEventListener('hashchange', () => {
	setTimeout(scheduleInject, 200)
})
window.addEventListener('popstate', () => {
	setTimeout(scheduleInject, 200)
})
