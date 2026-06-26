<!-- SPDX-FileCopyrightText: 2026 CRM Notes Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<!-- Mirror the real Contacts app, which renders contact rows with NcAvatar:
	     a Nextcloud USER contact (system address book) uses the core avatar
	     endpoint via :user, while a plain vCard contact uses its photo URL (when
	     present) via :url, falling back to generated initials from the display
	     name. NcAvatar handles device-pixel-ratio sizing and the initials
	     placeholder itself, so we no longer hand-roll any of that. -->
	<NcAvatar v-if="isUser"
		:user="uid"
		:display-name="name"
		:size="size"
		:disable-menu="true"
		:disable-tooltip="true"
		:show-user-status="false" />
	<NcAvatar v-else-if="photo"
		:url="photo"
		:display-name="name"
		:size="size"
		:is-no-user="true"
		:disable-menu="true"
		:disable-tooltip="true" />
	<NcAvatar v-else
		:display-name="name || uid"
		:size="size"
		:is-no-user="true"
		:disable-menu="true"
		:disable-tooltip="true" />
</template>

<script setup>
import NcAvatar from '@nextcloud/vue/components/NcAvatar'

defineProps({
	uid: { type: String, required: true },
	name: { type: String, default: '' },
	// CRM photo endpoint URL for a non-user contact that carries a PHOTO; empty
	// when the contact has no retrievable photo (then NcAvatar shows initials).
	photo: { type: String, default: '' },
	// Explicit flag from the contact model: true when the entry is a real
	// Nextcloud user account (system address book), so the core avatar endpoint
	// applies. Relying on this backend-provided value — rather than guessing from
	// the UID shape — keeps hyphenated usernames working.
	isUser: { type: Boolean, default: false },
	size: { type: Number, default: 40 },
})
</script>
