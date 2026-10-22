<template>
	<div class="dav-connect" data-test="dav-connect">
		<template v-if="account.davState === 'connected'">
			<NcNoteCard type="success">
				{{ t('apple_sync', 'Connected as {appleId}.', { appleId: account.davAppleId }) }}
			</NcNoteCard>
			<div class="dav-connect__actions">
				<NcButton variant="error" :disabled="!!busy" data-test="dav-disconnect" @click="disconnect">
					{{ t('apple_sync', 'Disconnect') }}
				</NcButton>
			</div>
		</template>

		<form v-else class="dav-connect__form" data-test="dav-form" @submit.prevent="connect">
			<NcNoteCard v-if="account.davState === 'error'" type="warning" data-test="dav-rejected">
				{{ t('apple_sync', 'Apple no longer accepts the app-specific password for {appleId}. Contacts and calendars are paused until you enter a new one.', { appleId: account.davAppleId }) }}
			</NcNoteCard>
			<NcTextField v-model="appleId"
				:label="t('apple_sync', 'Apple ID')"
				type="email"
				autocomplete="username"
				data-test="dav-apple-id"
				:disabled="!!busy" />
			<NcPasswordField v-model="password"
				:label="t('apple_sync', 'App-specific password')"
				autocomplete="off"
				placeholder="xxxx-xxxx-xxxx-xxxx"
				data-test="dav-password"
				:disabled="!!busy" />
			<ul class="dav-connect__help">
				<li>
					{{ t('apple_sync', 'Create one at account.apple.com → Sign-In and Security → App-Specific Passwords. App-specific passwords only work for mail, contacts and calendars, not for photos or files.') }}
					<a href="https://account.apple.com/account/manage" target="_blank" rel="noreferrer noopener">{{ t('apple_sync', 'Open account.apple.com') }} ↗</a>
				</li>
				<li>{{ t('apple_sync', 'Your normal Apple ID password does not work here.') }}</li>
			</ul>
			<div class="dav-connect__actions">
				<NcButton type="submit" variant="primary" :disabled="!!busy || !appleId || !password" data-test="dav-connect">
					<template #icon>
						<NcLoadingIcon v-if="busy === 'connect'" :size="20" />
					</template>
					{{ busy === 'connect' ? t('apple_sync', 'Checking…') : t('apple_sync', 'Connect') }}
				</NcButton>
			</div>
		</form>

		<NcNoteCard v-if="error" type="error" data-test="dav-error">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { showConfirmation, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { confirmPassword, PasswordConfirmationCancelledError } from '@nextcloud/password-confirmation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { api, ApiError } from '../services/api.js'
import '@nextcloud/password-confirmation/style.css'

const account = defineModel('account', { type: Object, required: true })

const appleId = ref(account.value.davAppleId ?? account.value.appleId ?? '')
const password = ref('')
const busy = ref('')
const error = ref('')

watch(() => account.value.davState, () => {
	password.value = ''
})

async function run(name, fn) {
	busy.value = name
	error.value = ''
	try {
		const result = await fn()
		if (result && typeof result === 'object' && 'davState' in result) {
			account.value = result
		}
		return result
	} catch (e) {
		if (e instanceof PasswordConfirmationCancelledError) {
			return null
		}
		if (e instanceof ApiError) {
			error.value = e.message
			if (e.account) {
				account.value = e.account
			}
			return null
		}
		throw e
	} finally {
		busy.value = ''
	}
}

function connect() {
	return run('connect', async () => {
		await confirmPassword()
		const result = await api.connectDav(appleId.value.trim(), password.value)
		showSuccess(t('apple_sync', 'Contacts and calendars connected'))
		return result
	})
}

async function disconnect() {
	const confirmed = await showConfirmation({
		name: t('apple_sync', 'Disconnect contacts and calendars?'),
		text: t('apple_sync', 'The app-specific password is deleted from this server. Contacts and calendars already synced stay in Nextcloud.'),
		labelConfirm: t('apple_sync', 'Disconnect'),
		labelReject: t('apple_sync', 'Cancel'),
		severity: 'warning',
	})
	if (!confirmed) {
		return
	}
	await run('disconnect', async () => {
		await confirmPassword()
		return api.disconnectDav()
	})
}
</script>

<style scoped lang="scss">
.dav-connect {
	max-width: 600px;

	&__form {
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 4px;
	}

	&__help {
		list-style: disc;
		padding-inline-start: 20px;
		color: var(--color-text-maxcontrast);

		li {
			margin-block: 2px;
		}

		a {
			color: var(--color-primary-element);
			text-decoration: underline;
		}
	}
}
</style>
