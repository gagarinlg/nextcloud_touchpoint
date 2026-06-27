<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!-- Rendered inside the app's own Vue tree (declarative), so it is themed,
	     focus-trapped and translatable — unlike the imperative dialog builder
	     which crashed at runtime when mounted outside a live Vue instance.
	     NcDialog only mounts its content while open, so this is cheap when idle. -->
	<NcDialog v-if="open"
		:open="open"
		:name="name"
		:message="message"
		:buttons="buttons"
		@update:open="onUpdateOpen" />
</template>

<script setup>
import { ref } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcDialog from '@nextcloud/vue/components/NcDialog'

const open = ref(false)
const name = ref('')
const message = ref('')
const confirmLabel = ref('')

// The promise resolver for the in-flight confirm() call, so the button
// callbacks (and a dismiss) settle exactly one awaiting caller.
let resolver = null

function settle(value) {
	const r = resolver
	resolver = null
	open.value = false
	if (r) r(value)
}

// NcDialog button definitions: a tertiary Cancel and a destructive (error)
// confirm, matching the previous UX. Callbacks resolve the open() promise.
const buttons = ref([
	{
		label: t('touchpoint', 'Cancel'),
		type: 'tertiary',
		callback: () => settle(false),
	},
	{
		label: t('touchpoint', 'Delete'),
		type: 'error',
		callback: () => settle(true),
	},
])

/**
 * Open the confirm dialog and resolve to true (confirmed) or false
 * (cancelled/dismissed).
 *
 * @param {object} opts
 * @param {string} opts.message body text / question
 * @param {string} [opts.name] dialog title
 * @param {string} [opts.confirmLabel] label for the destructive button
 * @return {Promise<boolean>}
 */
function show({ message: msg, name: title, confirmLabel: label } = {}) {
	// A previous, still-open prompt is treated as cancelled before reopening.
	if (resolver) settle(false)
	message.value = msg || ''
	name.value = title || t('touchpoint', 'Confirm')
	confirmLabel.value = label || t('touchpoint', 'Delete')
	buttons.value[1].label = confirmLabel.value
	open.value = true
	return new Promise((resolve) => {
		resolver = resolve
	})
}

// Dismissing the dialog (Esc / backdrop / close button) flips open to false;
// treat that as a cancel so the awaiting caller never hangs.
function onUpdateOpen(value) {
	if (!value) settle(false)
}

defineExpose({ show })
</script>
