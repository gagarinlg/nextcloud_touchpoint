<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-type-item">
		<NoteTypeBadge :type="type" />
		<div v-if="type.isDefault && !manageGlobal" class="crm-type-managed">
			{{ t('touchpoint', 'Managed by admin') }}
		</div>
		<div v-else class="crm-type-actions">
			<NcButton variant="tertiary"
				:aria-label="t('touchpoint', 'Edit type “{name}”', { name: type.name })"
				@click="$emit('edit', type)"
			>
				<template #icon><IconPencil :size="16" /></template>
			</NcButton>
			<NcButton variant="tertiary"
				:aria-label="t('touchpoint', 'Delete type “{name}”', { name: type.name })"
				:disabled="deleting"
				@click="$emit('delete', type)"
			>
				<template #icon>
					<NcLoadingIcon v-if="deleting" :size="16" />
					<IconDelete v-else :size="16" />
				</template>
			</NcButton>
		</div>
	</div>
</template>

<script setup>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import NoteTypeBadge from './NoteTypeBadge.vue'

defineProps({
	type: { type: Object, required: true },
	deleting: { type: Boolean, default: false },
	// True when this row is rendered inside the admin "Global note types"
	// management view itself — there, every row (all global-scoped) IS
	// editable/deletable by the admin. False (default) is the personal
	// "Note types" view, where a global default's Edit/Delete would silently
	// 404 (NoteTypeService::update()/delete() only match the caller's own
	// user_id rows), so those two actions are hidden in favour of a
	// "Managed by admin" label instead of a dead-end click.
	manageGlobal: { type: Boolean, default: false },
})

defineEmits(['edit', 'delete'])
</script>

<style scoped>
.crm-type-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline, 4px) * 2);
	padding: calc(var(--default-grid-baseline, 4px) * 2.5) calc(var(--default-grid-baseline, 4px) * 3.5);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

/* Allow the badge to shrink and ellipsize instead of forcing the row wider —
   a flex item's default min-width is `auto` (its content's natural width),
   which would otherwise override NoteTypeBadge's own max-width/ellipsis. */
.crm-type-item > :deep(.crm-type-badge) {
	min-width: 0;
}

.crm-type-actions {
	display: flex;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
}

.crm-type-managed {
	font-size: var(--font-size-small, 13px);
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}
</style>
