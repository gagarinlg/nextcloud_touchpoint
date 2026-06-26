<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NcModal :name="title" @close="notesStore.closeModal()">
		<div class="crm-modal-body">
			<!-- Contacts multi-select -->
			<div class="crm-form-row">
				<label for="note-contacts">
					{{ t('crm_notes', 'Contacts') }}
					<span class="crm-required" aria-hidden="true">*</span>
				</label>
				<NcSelect v-model="selectedContacts"
					input-id="note-contacts"
					:options="contactOptions"
					:multiple="true"
					required
					:aria-label-combobox="t('crm_notes', 'Contacts')"
					label="name"
					track-by="uid"
					:placeholder="t('crm_notes', 'Add contact…')" />
			</div>

			<!-- Note type -->
			<div class="crm-form-row">
				<label for="note-type">
					{{ t('crm_notes', 'Type') }}
					<span class="crm-required" aria-hidden="true">*</span>
				</label>
				<NcSelect v-model="form.noteTypeId"
					input-id="note-type"
					:options="typeOptions"
					required
					:aria-label-combobox="t('crm_notes', 'Type')"
					label="name"
					:reduce="o => o.id"
					:placeholder="t('crm_notes', 'Select type…')" />
			</div>

			<!-- Title -->
			<div class="crm-form-row">
				<label for="note-title">
					{{ t('crm_notes', 'Title') }}
					<span class="crm-required" aria-hidden="true">*</span>
				</label>
				<NcTextField ref="titleRef"
					input-id="note-title"
					v-model="form.title"
					label-outside
					required
					:placeholder="t('crm_notes', 'Note title')"
					maxlength="255" />
			</div>

			<!-- Content -->
			<div class="crm-form-row">
				<div class="crm-content-label-row">
					<label id="note-content-label" :for="previewMode ? null : 'note-content'">{{ t('crm_notes', 'Content') }}</label>
					<NcButton type="tertiary"
						size="small"
						:aria-pressed="previewMode"
						@click="togglePreview">
						<template #icon>
							<IconEye v-if="!previewMode" :size="16" />
							<IconPencil v-else :size="16" />
						</template>
						{{ previewMode ? t('crm_notes', 'Edit') : t('crm_notes', 'Preview') }}
					</NcButton>
				</div>
				<!-- ARIA toolbar pattern: a single Tab stop into the group, with
				     Left/Right (and Home/End) arrow keys moving focus between the
				     controls via roving tabindex. -->
				<div v-if="!previewMode"
					ref="toolbarRef"
					class="crm-md-toolbar"
					role="toolbar"
					:aria-label="t('crm_notes', 'Text formatting')"
					@keydown="onToolbarKeydown">
					<template v-for="(tool, idx) in mdTools" :key="tool.type">
						<!-- Visual separators between the format groups (3 / 3 / 3). -->
						<span v-if="idx === 3 || idx === 6" class="crm-md-sep" />
						<NcButton type="tertiary"
							size="small"
							:title="tool.label"
							:aria-label="tool.label"
							:tabindex="idx === activeToolIndex ? 0 : -1"
							@click="onToolClick(tool.type, idx)">
							<!-- Tools with a real icon component render it in the icon
							     slot (no emoji); the rest use a typographic glyph. -->
							<template v-if="tool.icon" #icon>
								<component :is="tool.icon" :size="16" />
							</template>
							<!-- eslint-disable-next-line vue/no-v-html -->
							<span v-if="!tool.icon" class="crm-md-glyph" aria-hidden="true" v-html="tool.glyph" />
						</NcButton>
					</template>
				</div>
				<textarea v-if="!previewMode"
					id="note-content"
					ref="editorRef"
					v-model="form.content"
					rows="8"
					class="crm-markdown-editor"
					:placeholder="t('crm_notes', 'Write your note here… (Markdown supported)')" />
				<!-- eslint-disable-next-line vue/no-v-html -->
				<div v-else
					class="crm-markdown-preview"
					role="region"
					aria-labelledby="note-content-label"
					v-html="previewContent" />
			</div>

			<!-- Files -->
			<!-- Not a <label>: this names a button + list group, not a single
			     form control, so it would be a dangling <label>. A labelled group
			     announces the files region to assistive tech instead. -->
			<div class="crm-form-row"
				role="group"
				aria-labelledby="note-files-label">
				<span id="note-files-label" class="crm-group-label">{{ t('crm_notes', 'Linked files') }}</span>
				<div class="crm-files-list">
					<div v-for="(f, i) in notesStore.pendingFiles" :key="i" class="crm-file-item">
						<IconFile :size="14" />
						<span class="crm-file-name">{{ fileLabel(f) }}</span>
						<NcButton type="tertiary"
							:aria-label="t('crm_notes', 'Remove file')"
							@click="notesStore.removePendingFile(i)">
							<template #icon><IconClose :size="14" /></template>
						</NcButton>
					</div>
				</div>
				<NcButton id="note-attach-file" @click="openFilePicker">
					<template #icon><IconFolder :size="18" /></template>
					{{ t('crm_notes', 'Attach file…') }}
				</NcButton>
			</div>

			<!-- Pin -->
			<NcCheckboxRadioSwitch v-model="form.isPinned">
				{{ t('crm_notes', 'Pin this note') }}
			</NcCheckboxRadioSwitch>

			<p v-if="missingFieldsHint"
				class="crm-save-hint"
				role="status"
				aria-live="polite">
				{{ missingFieldsHint }}
			</p>

			<!-- Legend explaining the red asterisk that marks required fields above. -->
			<p class="crm-required-legend">
				<span class="crm-required" aria-hidden="true">*</span>
				{{ t('crm_notes', 'required') }}
			</p>

			<div class="crm-modal-actions">
				<NcButton :disabled="notesStore.saving" @click="notesStore.closeModal()">{{ t('crm_notes', 'Cancel') }}</NcButton>
				<NcButton type="primary"
					:disabled="!canSave || notesStore.saving"
					@click="onSave">
					<template v-if="notesStore.saving" #icon>
						<NcLoadingIcon :size="16" />
					</template>
					{{ notesStore.saving ? t('crm_notes', 'Saving…') : t('crm_notes', 'Save') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import { showError, getFilePickerBuilder, FilePickerType } from '@nextcloud/dialogs'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import IconFolder from 'vue-material-design-icons/Folder.vue'
import IconClose from 'vue-material-design-icons/Close.vue'
import IconEye from 'vue-material-design-icons/Eye.vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconFile from 'vue-material-design-icons/File.vue'
import IconLinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import { renderMarkdown } from '../utils/markdown.js'
import { useNotesStore } from '../stores/notes.js'
import { useNoteTypesStore } from '../stores/noteTypes.js'
import { useContactsStore } from '../stores/contacts.js'

const props = defineProps({
	defaultContact: { type: Object, default: null },
})

const notesStore = useNotesStore()
const noteTypesStore = useNoteTypesStore()
const contactsStore = useContactsStore()

const previewMode = ref(false)
const editorRef = ref(null)
const titleRef = ref(null)
// Roving tabindex for the formatting toolbar: index of the single tabbable
// button. Arrow keys move it; the toolbar exposes one Tab stop.
const toolbarRef = ref(null)
const activeToolIndex = ref(0)

// Move roving focus to a toolbar button by index (wrapping), update the
// tabindex bookkeeping and physically focus the button.
function focusTool(index) {
	const count = mdTools.value.length
	const next = ((index % count) + count) % count
	activeToolIndex.value = next
	nextTick(() => {
		const buttons = toolbarRef.value?.querySelectorAll('button')
		buttons?.[next]?.focus()
	})
}

function onToolbarKeydown(event) {
	switch (event.key) {
	case 'ArrowRight':
	case 'ArrowDown':
		event.preventDefault()
		focusTool(activeToolIndex.value + 1)
		break
	case 'ArrowLeft':
	case 'ArrowUp':
		event.preventDefault()
		focusTool(activeToolIndex.value - 1)
		break
	case 'Home':
		event.preventDefault()
		focusTool(0)
		break
	case 'End':
		event.preventDefault()
		focusTool(mdTools.value.length - 1)
		break
	}
}

function onToolClick(type, index) {
	// Clicking a button also makes it the active (tabbable) one, keeping the
	// roving tabindex in sync with the last-used control.
	activeToolIndex.value = index
	insertFormat(type)
}

function focusEl(component) {
	// @nextcloud/vue components and plain elements differ; reach the focusable node.
	const node = component?.$el ?? component
	const focusable = node?.matches?.('input, textarea')
		? node
		: node?.querySelector?.('input, textarea')
	focusable?.focus?.()
}

function togglePreview() {
	previewMode.value = !previewMode.value
	if (!previewMode.value) {
		// Returning to edit mode: put the caret back in the textarea.
		nextTick(() => editorRef.value?.focus?.())
	}
}

const form = ref({
	title: '',
	content: '',
	noteTypeId: null,
	isPinned: false,
})

// Render via the shared pipeline so the in-modal preview demotes headings
// (and sanitises) exactly like NoteItem.vue and the Contacts tab — a user-typed
// "# Title" must not inject an <h1> that corrupts the dialog's heading outline.
const previewContent = computed(() => renderMarkdown(form.value.content))

// Markdown toolbar definitions. The glyphs are static, hardcoded markup (never
// user input), rendered via NcButton's default slot so the toolbar inherits NC
// theming and hover/focus behaviour instead of hand-rolled <button> styling.
const mdTools = computed(() => [
	{ type: 'bold', label: t('crm_notes', 'Bold'), glyph: '<b>B</b>' },
	{ type: 'italic', label: t('crm_notes', 'Italic'), glyph: '<i>I</i>' },
	{ type: 'strikethrough', label: t('crm_notes', 'Strikethrough'), glyph: '<s>S</s>' },
	{ type: 'heading', label: t('crm_notes', 'Heading'), glyph: 'H' },
	{ type: 'ul', label: t('crm_notes', 'Unordered list'), glyph: '&#8226;&#8212;' },
	{ type: 'ol', label: t('crm_notes', 'Ordered list'), glyph: '1.' },
	{ type: 'link', label: t('crm_notes', 'Link'), icon: IconLinkVariant },
	{ type: 'code', label: t('crm_notes', 'Code'), glyph: '&#60;/&#62;' },
	{ type: 'quote', label: t('crm_notes', 'Quote'), glyph: '&ldquo;&rdquo;' },
])

const selectedContacts = ref([])

const title = computed(() =>
	notesStore.editingNote
		? t('crm_notes', 'Edit note')
		: t('crm_notes', 'Add note'),
)

const typeOptions = computed(() => noteTypesStore.noteTypes)
const contactOptions = computed(() => contactsStore.contacts)

const canSave = computed(() =>
	form.value.title.trim()
	&& form.value.noteTypeId
	&& selectedContacts.value.length > 0,
)

// Tell the user which required field is still missing instead of leaving Save
// greyed out with no explanation. Lists the missing fields in form order.
const missingFieldsHint = computed(() => {
	if (canSave.value) return ''
	const missing = []
	if (selectedContacts.value.length === 0) missing.push(t('crm_notes', 'Contacts'))
	if (!form.value.noteTypeId) missing.push(t('crm_notes', 'Type'))
	if (!form.value.title.trim()) missing.push(t('crm_notes', 'Title'))
	return t('crm_notes', 'Required: {fields}', { fields: missing.join(', ') })
})

onMounted(() => {
	const note = notesStore.editingNote
	if (note) {
		form.value = {
			title: note.title,
			content: note.content || '',
			noteTypeId: note.noteTypeId,
			isPinned: note.isPinned,
		}
		// Populate selected contacts from note
		const uids = note.contactUids?.length ? note.contactUids : [note.contactUid]
		selectedContacts.value = uids
			.map(uid => contactsStore.byUid(uid))
			.filter(Boolean)
	} else if (props.defaultContact) {
		selectedContacts.value = [props.defaultContact]
	} else if (contactsStore.currentContact) {
		selectedContacts.value = [contactsStore.currentContact]
	}

	if (!form.value.noteTypeId && noteTypesStore.noteTypes.length) {
		form.value.noteTypeId = noteTypesStore.noteTypes[0].id
	}

	// Focus the title field once the modal has rendered so keyboard users land
	// on a meaningful first field rather than the dialog container.
	nextTick(() => focusEl(titleRef.value))
})

function insertFormat(type) {
	const el = editorRef.value
	if (!el) return
	const start = el.selectionStart
	const end = el.selectionEnd
	const sel = form.value.content.slice(start, end)
	let before = ''
	let after = ''
	let placeholder = ''

	switch (type) {
	case 'bold':
		before = '**'; after = '**'; placeholder = t('crm_notes', 'bold text')
		break
	case 'italic':
		before = '_'; after = '_'; placeholder = t('crm_notes', 'italic text')
		break
	case 'strikethrough':
		before = '~~'; after = '~~'; placeholder = t('crm_notes', 'text')
		break
	case 'heading':
		before = '## '; after = ''; placeholder = t('crm_notes', 'Heading')
		break
	case 'ul':
		before = '- '; after = ''; placeholder = t('crm_notes', 'List item')
		break
	case 'ol':
		before = '1. '; after = ''; placeholder = t('crm_notes', 'List item')
		break
	case 'link':
		before = '['; after = `](url)`; placeholder = sel || t('crm_notes', 'link text')
		break
	case 'code':
		before = sel.includes('\n') ? '```\n' : '`'
		after = sel.includes('\n') ? '\n```' : '`'
		placeholder = t('crm_notes', 'code')
		break
	case 'quote':
		before = '> '; after = ''; placeholder = t('crm_notes', 'Quote')
		break
	}

	const text = sel || placeholder
	const newContent = form.value.content.slice(0, start) + before + text + after + form.value.content.slice(end)
	form.value.content = newContent
	nextTick(() => {
		el.focus()
		const newCursor = start + before.length + text.length + after.length
		el.setSelectionRange(newCursor, newCursor)
	})
}

// Resolve a display label for a pending file. Guard the filePath fallback
// against a missing key / empty name and provide a generic label as a last
// resort, mirroring NoteItem.vue.
function fileLabel(f) {
	if (f.name) return f.name
	if (f.filePath) return f.filePath.split('/').pop()
	return t('crm_notes', 'Attachment')
}

async function openFilePicker() {
	try {
		const picker = getFilePickerBuilder(t('crm_notes', 'Select file'))
			.setMultiSelect(false)
			.setType(FilePickerType.Choose)
			.allowDirectories(false)
			.build()
		const path = await picker.pick()
		if (path) {
			notesStore.addPendingFile(path)
		}
	} catch (e) {
		// pick() rejects when the user cancels the dialog — that is not an error,
		// so swallow a cancellation but surface any real failure (e.g. the picker
		// could not be opened) instead of silently doing nothing.
		if (e && e.message && /cancel/i.test(e.message)) return
		showError(t('crm_notes', 'Could not open the file picker.'))
	}
}

async function onSave() {
	if (!canSave.value || notesStore.saving) return
	const primaryContact = selectedContacts.value[0]
	// Note: the Contacts manager only exposes a non-numeric address-book key
	// (e.g. 'contacts'), so there is no real numeric addressbook id to send. The
	// backend addressbook_id column is unused for authorization or lookups, so we
	// deliberately omit it here rather than persist a misleading 0 for every note.
	const payload = {
		contactUid: primaryContact.uid,
		noteTypeId: form.value.noteTypeId,
		title: form.value.title.trim(),
		content: form.value.content || null,
		isPinned: form.value.isPinned,
		contactUids: selectedContacts.value.map(c => c.uid),
	}
	try {
		await notesStore.save(payload, contactsStore.currentContact?.uid)
	} catch (e) {
		const msg = e?.response?.data?.message || e?.message || t('crm_notes', 'Failed to save note.')
		showError(msg)
	}
}
</script>

<style scoped>
.crm-modal-body {
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 4);
	width: 100%;
	max-width: min(480px, 100%);
	box-sizing: border-box;
}

.crm-form-row {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
}

.crm-form-row label,
.crm-group-label {
	font-weight: 600;
	font-size: var(--default-font-size, 14px);
}

.crm-required {
	color: var(--color-error);
	margin-inline-start: 2px;
}

.crm-save-hint {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	text-align: end;
}

.crm-required-legend {
	margin: 0;
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
}

.crm-content-label-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.crm-md-toolbar {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline, 4px);
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 1.5);
	background: var(--color-background-dark);
	border: 1px solid var(--color-border-dark);
	border-bottom: none;
	border-radius: var(--border-radius) var(--border-radius) 0 0;
}

.crm-md-glyph {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 16px;
	font-size: var(--font-size-small, 13px);
	line-height: 1;
}

/* 1px hairline width is conventional and intentionally left literal; the height
   follows the spacing scale. */
.crm-md-sep {
	width: 1px;
	height: calc(var(--default-grid-baseline, 4px) * 4.5);
	background: var(--color-border);
	margin: 0 var(--default-grid-baseline, 4px);
}

.crm-markdown-editor {
	width: 100%;
	border: 1px solid var(--color-border-dark);
	border-radius: 0 0 var(--border-radius) var(--border-radius);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	font-size: var(--default-font-size, 14px);
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-main-background);
	color: var(--color-main-text);
	resize: vertical;
	box-sizing: border-box;
}

/* The body is a hand-rolled <textarea> (the markdown toolbar needs direct caret
   access to the raw element, which an NcTextArea wrapper would hide). Match NC's
   :focus-visible treatment used by the other controls in this modal so the focus
   ring and border do not drift from the design system. */
.crm-markdown-editor:hover {
	border-color: var(--color-primary-element);
}

.crm-markdown-editor:focus,
.crm-markdown-editor:focus-visible {
	outline: none;
	border-color: var(--color-primary-element);
	box-shadow: 0 0 0 2px var(--color-primary-element);
}

.crm-markdown-preview {
	min-height: calc(var(--default-grid-baseline, 4px) * 30);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: calc(var(--default-grid-baseline, 4px) * 2) calc(var(--default-grid-baseline, 4px) * 3);
	font-size: var(--default-font-size, 14px);
	background: var(--color-background-hover);
	color: var(--color-main-text);
	line-height: var(--default-line-height, 1.6);
	/* Break long unbroken strings (pasted URLs/tokens) so the preview wraps inside
	   the modal instead of overflowing horizontally. */
	overflow-wrap: anywhere;
}

/* renderMarkdown() demotes every user heading by three (largest becomes <h4>),
   so the preview can never contain h1–h3. Style only the emitted levels. */
.crm-markdown-preview :deep(h4),
.crm-markdown-preview :deep(h5),
.crm-markdown-preview :deep(h6) {
	font-weight: 600;
	margin: calc(var(--default-grid-baseline, 4px) * 2) 0 var(--default-grid-baseline, 4px);
}

.crm-markdown-preview :deep(p) {
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-markdown-preview :deep(ul),
.crm-markdown-preview :deep(ol) {
	padding-left: calc(var(--default-grid-baseline, 4px) * 5);
	margin: 0 0 calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-markdown-preview :deep(code) {
	font-family: var(--font-face-monospace, monospace);
	background: var(--color-background-dark);
	padding: 1px calc(var(--default-grid-baseline, 4px) * 1);
	border-radius: var(--border-radius-small, 4px);
}

.crm-markdown-preview :deep(pre) {
	background: var(--color-background-dark);
	padding: calc(var(--default-grid-baseline, 4px) * 2);
	border-radius: var(--border-radius);
	overflow-x: auto;
}

.crm-markdown-preview :deep(a) {
	color: var(--color-primary-element);
}

.crm-files-list {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 4px);
}

.crm-file-item {
	display: flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1.5);
	background: var(--color-background-dark);
	border-radius: var(--border-radius);
	padding: var(--default-grid-baseline, 4px) calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-file-name {
	flex: 1;
	font-size: var(--font-size-small, 13px);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.crm-modal-actions {
	display: flex;
	justify-content: flex-end;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	margin-top: calc(var(--default-grid-baseline, 4px) * 1);
}
</style>
