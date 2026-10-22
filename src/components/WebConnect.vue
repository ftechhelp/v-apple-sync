<template>
	<div class="web-connect" data-test="web-connect">
		<!-- Connected -->
		<template v-if="account.webState === 'connected'">
			<NcNoteCard type="success">
				{{ t('apple_sync', 'Connected as {appleId}.', { appleId: account.appleId }) }}
			</NcNoteCard>
			<div class="web-connect__facts">
				<div v-if="account.lastAuthAt" class="web-connect__fact">
					<span class="web-connect__label">{{ t('apple_sync', 'Last sign-in') }}</span>
					<span>{{ formatDate(account.lastAuthAt) }}</span>
				</div>
				<div v-if="account.trustExpiresAt" class="web-connect__fact">
					<span class="web-connect__label">{{ t('apple_sync', 'Next two-factor code needed') }}</span>
					<span>{{ t('apple_sync', 'around {date}', { date: formatDate(account.trustExpiresAt) }) }}</span>
				</div>
			</div>
			<div class="web-connect__actions">
				<NcButton :disabled="!!busy" data-test="check" @click="check">
					<template #icon>
						<NcLoadingIcon v-if="busy === 'check'" :size="20" />
					</template>
					{{ t('apple_sync', 'Check connection') }}
				</NcButton>
				<NcButton variant="error" :disabled="!!busy" data-test="disconnect" @click="disconnect">
					{{ t('apple_sync', 'Disconnect') }}
				</NcButton>
			</div>
		</template>

		<!-- Waiting for the 2FA code -->
		<template v-else-if="account.webState === 'needs_2fa'">
			<form v-if="mode !== 'sms-pick'" class="web-connect__form" @submit.prevent="verify">
				<p v-if="mode === 'sms-code'">
					{{ t('apple_sync', 'Enter the code sent by text message to {number}.', { number: phone?.number }) }}
				</p>
				<p v-else>
					{{ t('apple_sync', 'Enter the verification code shown on your iPhone, iPad or Mac. If a sign-in request appears first, tap Allow.') }}
				</p>
				<NcTextField v-model="code"
					:label="t('apple_sync', 'Verification code')"
					inputmode="numeric"
					autocomplete="one-time-code"
					maxlength="6"
					data-test="code"
					:disabled="!!busy"
					@update:model-value="autoSubmit" />
				<div class="web-connect__actions">
					<NcButton type="submit" variant="primary" :disabled="!!busy || code.length === 0" data-test="verify">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'verify'" :size="20" />
						</template>
						{{ t('apple_sync', 'Verify') }}
					</NcButton>
					<NcButton v-if="mode === 'device'" variant="tertiary" :disabled="!!busy" data-test="resend" @click="resend">
						{{ t('apple_sync', 'Send a new code') }}
					</NcButton>
					<NcButton v-if="mode === 'device'" variant="tertiary" :disabled="!!busy" data-test="text-me" @click="pickPhone">
						{{ t('apple_sync', 'Text me instead') }}
					</NcButton>
					<NcButton v-if="mode === 'sms-code'" variant="tertiary" :disabled="!!busy" @click="sendSms">
						{{ t('apple_sync', 'Send the text again') }}
					</NcButton>
					<NcButton v-if="mode === 'sms-code'" variant="tertiary" :disabled="!!busy" @click="mode = 'device'">
						{{ t('apple_sync', 'Use a device code') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="!!busy" data-test="cancel" @click="cancel">
						{{ t('apple_sync', 'Cancel') }}
					</NcButton>
				</div>
			</form>

			<div v-else class="web-connect__form">
				<p>{{ t('apple_sync', 'Send the code to:') }}</p>
				<NcCheckboxRadioSwitch v-for="p in phones"
					:key="p.id"
					v-model="phoneId"
					type="radio"
					name="apple-sync-phone"
					:value="String(p.id)"
					:data-test="'phone-' + p.id">
					{{ p.number }}
				</NcCheckboxRadioSwitch>
				<div class="web-connect__actions">
					<NcButton variant="primary" :disabled="!!busy || !phoneId" data-test="send-sms" @click="sendSms">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'sms'" :size="20" />
						</template>
						{{ t('apple_sync', 'Send text message') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="!!busy" @click="mode = 'device'">
						{{ t('apple_sync', 'Back') }}
					</NcButton>
				</div>
			</div>
		</template>

		<!-- Not connected, or Apple wants a fresh 2FA code -->
		<template v-else>
			<NcNoteCard v-if="account.webState === 'reauth_required'" type="warning" data-test="reauth">
				{{ t('apple_sync', 'Apple needs a new two-factor verification for {appleId}. This happens about once a month; syncing of photos and iCloud Drive is paused until then.', { appleId: account.appleId }) }}
				<p v-if="account.lastError" class="web-connect__detail">
					{{ account.lastError }}
				</p>
			</NcNoteCard>

			<div v-if="canReconnect && !showForm" class="web-connect__actions">
				<NcButton variant="primary" :disabled="!!busy" data-test="reconnect" @click="reconnect">
					<template #icon>
						<NcLoadingIcon v-if="busy === 'connect'" :size="20" />
					</template>
					{{ t('apple_sync', 'Reconnect') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="!!busy" @click="showForm = true">
					{{ t('apple_sync', 'Use a different Apple ID or password') }}
				</NcButton>
			</div>

			<form v-else class="web-connect__form" data-test="connect-form" @submit.prevent="connect">
				<NcTextField v-model="appleId"
					:label="t('apple_sync', 'Apple ID')"
					type="email"
					autocomplete="username"
					data-test="apple-id"
					:disabled="!!busy" />
				<NcPasswordField v-model="password"
					:label="t('apple_sync', 'Apple ID password')"
					autocomplete="current-password"
					data-test="password"
					:disabled="!!busy" />
				<ul class="web-connect__requirements">
					<li>{{ t('apple_sync', 'Use your normal Apple ID password. App-specific passwords do not work for photos and iCloud Drive.') }}</li>
					<li>{{ t('apple_sync', 'On your iPhone, turn on Settings → your name → iCloud → Access iCloud Data on the Web.') }}</li>
					<li>{{ t('apple_sync', 'Advanced Data Protection must be off.') }}</li>
					<li>{{ t('apple_sync', 'Your password is stored encrypted on this server so syncing can sign in again by itself. Apple still asks for a new code about once a month.') }}</li>
				</ul>
				<div class="web-connect__actions">
					<NcButton type="submit" variant="primary" :disabled="!!busy || !appleId || !password" data-test="connect">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'connect'" :size="20" />
						</template>
						{{ busy === 'connect' ? t('apple_sync', 'Signing in…') : t('apple_sync', 'Connect') }}
					</NcButton>
					<NcButton v-if="canReconnect" variant="tertiary" :disabled="!!busy" @click="showForm = false">
						{{ t('apple_sync', 'Back') }}
					</NcButton>
				</div>
			</form>
		</template>

		<NcNoteCard v-if="error" type="error" data-test="error">
			{{ error }}
		</NcNoteCard>
		<p v-if="info" class="web-connect__info" role="status" data-test="info">
			{{ info }}
		</p>
	</div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { showConfirmation, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { confirmPassword, PasswordConfirmationCancelledError } from '@nextcloud/password-confirmation'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { api, ApiError } from '../services/api.js'
import '@nextcloud/password-confirmation/style.css'

const account = defineModel('account', { type: Object, required: true })

const appleId = ref(account.value.appleId ?? '')
const password = ref('')
const code = ref('')
const mode = ref('device') // device | sms-pick | sms-code
const phones = ref([])
const phoneId = ref('')
const showForm = ref(false)
const busy = ref('')
const error = ref('')
const info = ref('')

const canReconnect = computed(() => account.value.webState === 'reauth_required' && account.value.hasStoredPassword && !!account.value.appleId)
const phone = computed(() => phones.value.find((p) => String(p.id) === phoneId.value))

watch(() => account.value.webState, () => {
	code.value = ''
	password.value = ''
	mode.value = 'device'
	showForm.value = false
})

async function run(name, fn) {
	busy.value = name
	error.value = ''
	info.value = ''
	try {
		const result = await fn()
		if (result && typeof result === 'object' && 'webState' in result) {
			account.value = result
		}
		return result
	} catch (e) {
		if (e instanceof PasswordConfirmationCancelledError) {
			return null
		}
		if (e instanceof ApiError) {
			// "Apple wants a new code" is shown by the reconnect card instead.
			if (!(e.status === 409 && e.account?.webState === 'reauth_required')) {
				error.value = e.message
			}
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
		return api.connect(appleId.value.trim(), password.value)
	})
}

function reconnect() {
	return run('connect', async () => {
		await confirmPassword()
		return api.connect(account.value.appleId, '')
	})
}

function verify() {
	const id = mode.value === 'sms-code' ? Number(phoneId.value) : null
	return run('verify', async () => {
		const result = await api.verify(code.value, id)
		showSuccess(t('apple_sync', 'iCloud connected'))
		return result
	}).then(() => {
		code.value = ''
	})
}

function autoSubmit(value) {
	if (/^\d{6}$/.test(String(value)) && !busy.value) {
		verify()
	}
}

function resend() {
	return run('resend', async () => {
		const result = await api.resend()
		info.value = t('apple_sync', 'A new code was sent to your devices.')
		return result
	})
}

function pickPhone() {
	return run('phones', async () => {
		const result = await api.phones()
		phones.value = result.phones
		if (phones.value.length === 0) {
			error.value = t('apple_sync', 'This Apple ID has no trusted phone numbers.')
			return null
		}
		phoneId.value = String(phones.value[0].id)
		mode.value = 'sms-pick'
		return null
	})
}

function sendSms() {
	return run('sms', async () => {
		const result = await api.sms(Number(phoneId.value))
		mode.value = 'sms-code'
		info.value = t('apple_sync', 'Text message sent.')
		return result
	})
}

function cancel() {
	return run('cancel', () => api.cancel())
}

function check() {
	return run('check', async () => {
		const result = await api.check()
		info.value = t('apple_sync', 'The connection works.')
		return result
	})
}

async function disconnect() {
	const confirmed = await showConfirmation({
		name: t('apple_sync', 'Disconnect iCloud?'),
		text: t('apple_sync', 'Your Apple ID password and session are deleted from this server. Files and photos already synced stay in Nextcloud.'),
		labelConfirm: t('apple_sync', 'Disconnect'),
		labelReject: t('apple_sync', 'Cancel'),
		severity: 'warning',
	})
	if (!confirmed) {
		return
	}
	await run('disconnect', async () => {
		await confirmPassword()
		return api.disconnect()
	})
	appleId.value = ''
}

function formatDate(seconds) {
	return new Date(seconds * 1000).toLocaleDateString(undefined, { year: 'numeric', month: 'long', day: 'numeric' })
}
</script>

<style scoped lang="scss">
.web-connect {
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

	&__facts {
		margin: 8px 0 12px;
	}

	&__fact {
		display: flex;
		flex-wrap: wrap;
		gap: 0 16px;
		padding-block: 2px;
	}

	&__label {
		flex: 0 0 220px;
		color: var(--color-text-maxcontrast);
	}

	&__requirements {
		list-style: disc;
		padding-inline-start: 20px;
		color: var(--color-text-maxcontrast);

		li {
			margin-block: 2px;
		}
	}

	&__detail {
		margin-top: 4px;
		color: var(--color-text-maxcontrast);
	}

	&__info {
		margin-top: 8px;
		color: var(--color-text-maxcontrast);
	}
}
</style>
