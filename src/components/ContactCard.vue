<!-- SPDX-FileCopyrightText: 2026 Touchpoint Contributors -->
<!-- SPDX-License-Identifier: AGPL-3.0-or-later -->
<template>
	<div class="crm-contact-card">
		<NcLoadingIcon v-if="state === 'loading'" :size="32" />
		<NcEmptyContent v-else-if="state === 'error'"
			:name="t('touchpoint', 'Contact details unavailable')"
			:description="t('touchpoint', 'The Contacts app could not render this card.')">
			<template #icon><IconAccountOff :size="48" /></template>
			<template #action>
				<NcButton @click="mount">{{ t('touchpoint', 'Retry') }}</NcButton>
			</template>
		</NcEmptyContent>
		<!-- v-show (not v-if): the mount target must exist in the DOM before we
		     mount the embedded card into it, even while it is still hidden. -->
		<div v-show="state === 'ready'" ref="mountEl" class="crm-contact-card__mount" />
	</div>
</template>

<script setup>
import { ref, watch, onMounted, onBeforeUnmount } from 'vue'
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcButton from '@nextcloud/vue/components/NcButton'
import IconAccountOff from 'vue-material-design-icons/AccountOff.vue'

const props = defineProps({
	// Email address of the contact to render. The Contacts OCA API keys the
	// embedded card by email, not by UID.
	email: { type: String, required: true },
})

const mountEl = ref(null)
const state = ref('loading') // 'loading' | 'ready' | 'error'

// Handle returned by mountContactDetails(); call its $destroy to unmount the
// embedded Vue app. `token` guards against races: if the contact changes while
// we are still waiting for the API, the stale mount must not win.
let handle = null
let token = 0

function destroy() {
	if (handle && typeof handle.$destroy === 'function') {
		try {
			handle.$destroy()
		} catch (e) {
			// Already torn down — nothing to do.
		}
	}
	handle = null
}

/**
 * The Contacts OCA-API bundle (window.OCA.Contacts) is added to this page by a
 * server-side dispatch of LoadContactsOcaApiEvent, but it loads asynchronously.
 * Poll briefly for it before giving up.
 *
 * @return {Promise<object|null>} the API object, or null if it never appeared
 */
function waitForApi() {
	return new Promise((resolve) => {
		let tries = 0
		const check = () => {
			const api = window.OCA?.Contacts
			if (api && typeof api.mountContactDetails === 'function') {
				resolve(api)
				return
			}
			if (tries++ > 50) { // ~5s at 100ms
				resolve(null)
				return
			}
			window.setTimeout(check, 100)
		}
		check()
	})
}

async function mount() {
	const myToken = ++token
	destroy()
	state.value = 'loading'
	const email = props.email
	if (!email) {
		state.value = 'error'
		return
	}
	const api = await waitForApi()
	// Bail if the selection changed while waiting, or the API never appeared.
	if (myToken !== token) {
		return
	}
	if (!api || !mountEl.value) {
		state.value = 'error'
		return
	}
	try {
		handle = api.mountContactDetails(mountEl.value, email)
		state.value = 'ready'
	} catch (e) {
		console.error('Touchpoint: failed to mount embedded contact card', e)
		state.value = 'error'
	}
}

onMounted(mount)
watch(() => props.email, mount)
onBeforeUnmount(() => {
	token++ // invalidate any in-flight mount
	destroy()
})
</script>

<style scoped>
.crm-contact-card {
	margin-block-end: calc(var(--default-grid-baseline, 4px) * 2);
}

.crm-contact-card__mount {
	/* The embedded Contacts card brings its own layout; just keep it in bounds. */
	max-width: 100%;
	overflow-x: auto;
}
</style>
