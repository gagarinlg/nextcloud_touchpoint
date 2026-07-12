<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!-- Render nothing for a missing/deleted type rather than an empty colored
	pill, which reads as a rendering glitch. Callers may also guard with
	v-if; this is a second line of defence. -->
	<span v-if="type && type.name" class="crm-type-badge" :style="badgeStyle" :title="type.name">
		<component :is="iconComponent" v-if="iconComponent" :size="13" class="crm-type-badge-icon" aria-hidden="true" />
		<span class="crm-type-badge-label">{{ type.name }}</span>
	</span>
</template>

<script setup>
import { computed } from 'vue'
import { readableTextColor } from '../utils/color.js'
import { iconComponentForType } from '../utils/noteTypeIcon.js'

const props = defineProps({
	type: { type: Object, default: null },
})

const iconComponent = computed(() => iconComponentForType(props.type?.icon))

const badgeStyle = computed(() => {
	const bg = props.type?.color || 'var(--color-background-dark)'
	return {
		background: bg,
		color: props.type?.color
			? readableTextColor(props.type.color)
			: 'var(--color-main-text)',
	}
})
</script>

<style scoped>
.crm-type-badge {
	display: inline-flex;
	align-items: center;
	gap: calc(var(--default-grid-baseline, 4px) * 1);
	padding: calc(var(--default-grid-baseline, 4px) * 0.5) calc(var(--default-grid-baseline, 4px) * 2.5);
	border-radius: var(--border-radius-pill, 16px);
	font-size: var(--font-size-small, 13px);
	font-weight: 600;
	max-width: 100%;
}

.crm-type-badge-icon {
	flex-shrink: 0;
}

.crm-type-badge-label {
	overflow: hidden;
	white-space: nowrap;
	text-overflow: ellipsis;
}
</style>
