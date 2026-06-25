<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-note-item" :class="{ 'is-pinned': note.isPinned }">
		<button v-if="showContact && contactName"
			type="button"
			class="crm-note-contact-name"
			@click.stop="$emit('contact-click', note.contactUid)">
			{{ contactName }}
		</button>
		<div class="crm-note-item-header">
			<NoteTypeBadge v-if="noteType" :type="noteType" />
			<span class="crm-note-title">{{ note.title }}</span>
			<IconPin v-if="note.isPinned"
				class="crm-pin-indicator"
				:size="16"
				role="img"
				:aria-label="t('crm_notes', 'Pinned')" />
		</div>
		<!-- eslint-disable-next-line vue/no-v-html -->
		<div v-if="note.content" class="crm-note-content" v-html="renderedContent" />
		<div v-if="note.files && note.files.length" class="crm-note-files">
			<span v-for="f in note.files" :key="f.id" class="crm-file-chip">
				<IconFile :size="12" class="crm-file-chip-icon" /><span class="crm-file-chip-label">{{ fileLabel(f) }}</span>
			</span>
		</div>
		<div v-if="linkedContacts.length" class="crm-note-linked">
			<span class="crm-linked-label">{{ t('crm_notes', 'Also linked to:') }}</span>
			<span v-for="c in linkedContacts" :key="c.uid" class="crm-linked-chip">{{ c.name }}</span>
		</div>
		<div class="crm-note-footer">
			<div class="crm-note-meta">
				<span class="crm-note-created">{{ createdByline }}</span>
				<span v-if="editedByline" class="crm-note-modified">{{ editedByline }}</span>
			</div>
			<div class="crm-note-actions">
				<NcButton type="tertiary" :disabled="deleting" @click="$emit('edit', note)">
					<template #icon><IconPencil :size="16" /></template>
					{{ t('crm_notes', 'Edit') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="deleting" @click="$emit('delete', note)">
					<template #icon>
						<NcLoadingIcon v-if="deleting" :size="16" />
						<IconDelete v-else :size="16" />
					</template>
					{{ deleting ? t('crm_notes', 'Deleting…') : t('crm_notes', 'Delete') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'
import { translate as t, getLocale } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconPin from 'vue-material-design-icons/Pin.vue'
import IconFile from 'vue-material-design-icons/File.vue'
import NoteTypeBadge from './NoteTypeBadge.vue'
import { renderMarkdown } from '../utils/markdown.js'
import { useNoteTypesStore } from '../stores/noteTypes.js'
import { useContactsStore } from '../stores/contacts.js'

const props = defineProps({
	note: { type: Object, required: true },
	showContact: { type: Boolean, default: false },
	deleting: { type: Boolean, default: false },
})

defineEmits(['edit', 'delete', 'contact-click'])

// Resolve a display label for an attached file. Share recipients receive a
// payload with only {id, noteId, name} (no filePath) and `name` may be an empty
// string when the stored path was empty, so guard the filePath fallback against
// a missing key and provide a generic label as a last resort.
function fileLabel(f) {
	if (f.name) return f.name
	if (f.filePath) return f.filePath.split('/').pop()
	return t('crm_notes', 'Attachment')
}

const noteTypesStore = useNoteTypesStore()
const contactsStore = useContactsStore()

const noteType = computed(() => noteTypesStore.byId(props.note.noteTypeId))

const contactName = computed(() => {
	const c = contactsStore.byUid(props.note.contactUid)
	return c?.name || props.note.contactUid
})

const linkedContacts = computed(() => {
	if (!props.note.contactUids || props.note.contactUids.length <= 1) return []
	return props.note.contactUids
		.filter(uid => uid !== props.note.contactUid)
		.map(uid => contactsStore.byUid(uid) || { uid, name: uid })
})

const _locale = getLocale().replace('_', '-')
const _dateFormatter = new Intl.DateTimeFormat(_locale, {
	year: 'numeric', month: 'short', day: 'numeric',
	hour: '2-digit', minute: '2-digit',
})

// The API serializes timestamps as ISO-8601 (DateTimeInterface::ATOM), which
// Date can parse directly including the timezone offset — no fragile
// space-to-'T' munging or implicit-local-time assumptions.
function formatTimestamp(value) {
	if (!value) return ''
	const d = new Date(value)
	return Number.isNaN(d.getTime()) ? '' : _dateFormatter.format(d)
}

const formattedDate = computed(() => formatTimestamp(props.note.createdAt))

const formattedUpdatedDate = computed(() => formatTimestamp(props.note.updatedAt))

// Compose each byline as a single interpolated string so translators control
// word order for "created X by Y" / "edited X by Y" (CLAUDE.md forbids stitching
// separate t() fragments). Separate variants cover the author-omitted case so we
// never glue a translated phrase to a bare value or a hardcoded separator.
const createdByline = computed(() => {
	const date = formattedDate.value
	return props.note.createdBy
		? t('crm_notes', 'created {date} by {author}', { date, author: props.note.createdBy })
		: t('crm_notes', 'created {date}', { date })
})

const editedByline = computed(() => {
	// Only show an "edited" line when the note was actually modified after
	// creation.
	if (!props.note.updatedBy || props.note.updatedAt === props.note.createdAt) {
		return ''
	}
	const date = formattedUpdatedDate.value
	return props.note.updatedBy !== props.note.createdBy
		? t('crm_notes', 'edited {date} by {author}', { date, author: props.note.updatedBy })
		: t('crm_notes', 'edited {date}', { date })
})

// Note content is rendered inside a card that sits under the view's <h2>, so any
// h1–h3 a user writes (e.g. "# Title") would corrupt the document heading
// outline for screen-reader navigation. The shared renderMarkdown() pipeline
// demotes heading levels by three (largest user heading becomes an <h4>) before
// sanitising, identical to the modal preview and the Contacts tab.
const renderedContent = computed(() => renderMarkdown(props.note.content))
</script>

<style scoped>
.crm-note-item {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 3);
}

.crm-note-item.is-pinned {
	border-color: var(--color-primary-element);
}

.crm-note-contact-name {
	display: block;
	font-size: var(--font-size-small, 13px);
	font-weight: 600;
	color: var(--color-primary-element);
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 1);
	cursor: pointer;
	background: none;
	border: none;
	padding: 0;
	text-align: start;
}

.crm-note-contact-name:hover {
	text-decoration: underline;
}

.crm-note-contact-name:focus-visible {
	outline: 2px solid var(--color-primary-element);
	border-radius: var(--border-radius);
}

.crm-note-item-header {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	flex-wrap: wrap;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 1.5);
}

.crm-note-title {
	font-weight: 600;
	font-size: var(--default-font-size, 14px);
	/* Allow long unbroken titles (pasted URLs/filenames) to wrap inside the card
	   instead of pushing the layout or clipping at the flex boundary. */
	min-width: 0;
	overflow-wrap: anywhere;
}

.crm-pin-indicator {
	margin-left: auto;
	color: var(--color-primary-element);
}

.crm-note-content {
	/* Primary note substance — full reading contrast. --color-text-maxcontrast is
	   reserved for secondary meta/byline and linked-contacts rows. */
	color: var(--color-main-text);
	font-size: var(--font-size-small, 13px);
	margin: calc(var(--default-grid-baseline, 4px) * 1) 0 calc(var(--default-grid-baseline, 4px) * 2);
	line-height: 1.6;
	/* Break long unbroken strings (pasted URLs/tokens/filenames) inside the card
	   instead of letting a paragraph push the layout and cause horizontal body
	   scroll on narrow viewports — matching the .crm-note-title treatment. */
	overflow-wrap: anywhere;
}

.crm-note-content :deep(p) {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5);
}

.crm-note-content :deep(p:last-child) {
	margin-bottom: 0;
}

.crm-note-content :deep(ul),
.crm-note-content :deep(ol) {
	padding-left: calc(var(--default-grid-baseline, 4px) * 4.5);
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 1.5);
}

/* renderMarkdown() demotes every user heading by three (largest becomes <h4>),
   so rendered note content can never contain h1–h3. Style only the levels the
   pipeline can actually emit, matching the Contacts-tab stylesheet. */
.crm-note-content :deep(h4),
.crm-note-content :deep(h5),
.crm-note-content :deep(h6) {
	font-weight: 600;
	margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0 calc(var(--default-grid-baseline, 4px) * 0.5);
	color: var(--color-main-text);
}

.crm-note-content :deep(code) {
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	padding: 1px calc(var(--default-grid-baseline, 4px) * 1);
	border-radius: 3px;
}

.crm-note-content :deep(pre) {
	background: var(--color-background-dark);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-radius: var(--border-radius);
	overflow-x: auto;
}

.crm-note-content :deep(a) {
	color: var(--color-primary-element);
}

.crm-note-content :deep(blockquote) {
	border-left: 3px solid var(--color-border-dark);
	padding-left: calc(var(--default-grid-baseline, 4px) * 2);
	margin: calc(var(--default-grid-baseline, 4px) * 1) 0;
	color: var(--color-text-maxcontrast);
}

.crm-note-files {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
	margin: calc(var(--default-grid-baseline, 4px) * 1.5) 0;
}

.crm-file-chip {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: 2px calc(var(--default-grid-baseline, 4px) * 2);
	font-size: var(--font-size-small, 13px);
	/* Clamp very long unbroken filenames with an ellipsis (matching the modal's
	   .crm-file-name) so a single chip cannot stretch past the card's content
	   width on narrow viewports. */
	max-width: 100%;
	min-width: 0;
}

/* Keep the file icon at its intrinsic size; only the label truncates. */
.crm-file-chip-icon {
	flex: 0 0 auto;
}

.crm-file-chip-label {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.crm-note-linked {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	margin: calc(var(--default-grid-baseline, 4px) * 1) 0;
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	flex-wrap: wrap;
	align-items: center;
}

.crm-linked-chip {
	background: var(--color-background-dark);
	border-radius: var(--border-radius-pill, 16px);
	padding: 1px calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-note-footer {
	display: flex;
	justify-content: space-between;
	align-items: flex-start;
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-note-meta {
	display: flex;
	flex-wrap: wrap;
	gap: 2px calc(var(--default-grid-baseline, 4px) * 1.5);
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	line-height: 1.5;
}

.crm-note-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	opacity: 0;
	transition: opacity 0.15s;
}

/* Reveal the actions on hover (mouse) and on keyboard focus, so they are not
   hidden from keyboard users tabbing onto the buttons (WCAG). */
.crm-note-item:hover .crm-note-actions,
.crm-note-item:focus-within .crm-note-actions {
	opacity: 1;
}

/* On coarse / no-hover pointers (touch) there is no hover to reveal them, so
   keep the actions permanently visible — otherwise they are undiscoverable. */
@media (hover: none) {
	.crm-note-actions {
		opacity: 1;
	}
}
</style>
