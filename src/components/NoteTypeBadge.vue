<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!-- Render nothing for a missing/deleted type rather than an empty colored
	     pill, which reads as a rendering glitch. Callers may also guard with
	     v-if; this is a second line of defence. -->
	<span v-if="type && type.name" class="crm-type-badge" :style="badgeStyle">
		<component :is="iconComponent" v-if="iconComponent" :size="13" class="crm-type-badge-icon" />
		{{ type.name }}
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
	gap: 4px;
	padding: 2px 10px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
}

.crm-type-badge-icon {
	flex-shrink: 0;
}
</style>
