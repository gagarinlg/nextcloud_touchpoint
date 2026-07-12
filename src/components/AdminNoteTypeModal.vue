<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<NoteTypeFormModal scope="global"
		:editing-type="editingType"
		:saving="saving"
		:name-error="nameError"
		@update:name-error="$emit('update:nameError', $event)"
		@close="$emit('close')"
		@save="$emit('save', $event)"
	/>
</template>

<script setup>
import NoteTypeFormModal from './NoteTypeFormModal.vue'

defineProps({
	editingType: { type: Object, default: null },
	saving: { type: Boolean, default: false },
	nameError: { type: String, default: '' },
})

defineEmits(['close', 'save', 'update:nameError'])
</script>
