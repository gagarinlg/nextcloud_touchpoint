<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-contact-icon" :style="iconStyle">
		<span class="crm-contact-initials" :style="initialsStyle">{{ initials }}</span>
		<img v-if="photoSrc"
			class="crm-contact-avatar"
			:src="photoSrc"
			alt=""
			@error="onImgError">
	</div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { generateUrl } from '@nextcloud/router'
import { stringToColor, getInitials, readableTextColor } from '../utils/color.js'

const props = defineProps({
	uid: { type: String, required: true },
	name: { type: String, default: '' },
	photo: { type: String, default: '' },
	// Explicit flag from the contact model: true when the entry is a real
	// Nextcloud user account (system address book), so we can use the core avatar
	// endpoint. Inferring this from the UID shape would mishandle hyphenated
	// usernames, so we rely on the backend-provided value.
	isUser: { type: Boolean, default: false },
	size: { type: Number, default: 40 },
})

const imgFailed = ref(false)

const bgColor = computed(() => stringToColor(props.uid || props.name))
const initials = computed(() => getInitials(props.name))

const iconStyle = computed(() => ({
	backgroundColor: bgColor.value,
	width: `${props.size}px`,
	height: `${props.size}px`,
}))

const initialsStyle = computed(() => ({
	fontSize: `${Math.round(props.size * 0.4)}px`,
	// Pick black/white per WCAG luminance against the generated background so the
	// initials stay legible across the whole hue wheel — fixed white failed AA at
	// light (yellow/green) hues. Mirrors NoteTypeBadge's readableTextColor() use.
	color: readableTextColor(bgColor.value),
}))

const photoSrc = computed(() => {
	if (imgFailed.value) return null
	if (props.photo) return props.photo
	// NC user avatars are served by the core avatar endpoint. Request the image
	// at the device's pixel density so we never upscale a low-res avatar on
	// HiDPI displays, and build the URL via generateUrl so it honors the
	// instance web root instead of a hardcoded /index.php prefix.
	if (props.isUser) {
		const dpr = Math.min(window.devicePixelRatio || 1, 4)
		const pxSize = Math.round(props.size * dpr)
		return generateUrl('/avatar/{userId}/{size}', { userId: props.uid, size: pxSize })
	}
	return null
})

function onImgError() {
	imgFailed.value = true
}
</script>

<style scoped>
.crm-contact-icon {
	position: relative;
	border-radius: 50%;
	flex-shrink: 0;
	overflow: hidden;
}

.crm-contact-initials {
	position: absolute;
	inset: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	font-weight: bold;
	/* Foreground color is set inline per-avatar via readableTextColor() so the
	   initials always meet WCAG contrast against the generated background. */
}

.crm-contact-avatar {
	position: absolute;
	inset: 0;
	width: 100%;
	height: 100%;
	object-fit: cover;
	z-index: 1;
}
</style>
