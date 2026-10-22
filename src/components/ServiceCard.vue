<template>
	<div class="service" :data-test="'service-' + service.service">
		<div class="service__head">
			<NcCheckboxRadioSwitch :model-value="service.enabled"
				type="switch"
				:disabled="!!busy || (!service.connected && !service.enabled)"
				:data-test="'toggle-' + service.service"
				@update:model-value="toggle">
				<span class="service__name">{{ title }}</span>
			</NcCheckboxRadioSwitch>
			<span class="service__summary" :data-test="'summary-' + service.service">
				<NcLoadingIcon v-if="syncing" :size="16" />
				{{ summary }}
			</span>
		</div>

		<div class="service__body">
			<p class="service__where">
				{{ where }}
			</p>
			<p v-if="!service.connected" class="service__hint">
				{{ service.credential === 'dav'
					? t('apple_sync', 'Connect with an app-specific password above first.')
					: t('apple_sync', 'Sign in with your Apple ID above first.') }}
			</p>

			<template v-if="service.enabled">
				<div v-if="hasTarget" class="service__target">
					<span class="service__label">{{ t('apple_sync', 'Folder') }}</span>
					<code :data-test="'target-' + service.service">{{ service.target }}</code>
					<NcButton variant="tertiary" :disabled="!!busy || syncing" :data-test="'choose-' + service.service" @click="chooseFolder">
						{{ t('apple_sync', 'Change') }}
					</NcButton>
				</div>

				<NcCheckboxRadioSwitch :model-value="service.mirrorDeletions"
					:disabled="!!busy"
					:data-test="'mirror-' + service.service"
					@update:model-value="(value) => update({ mirrorDeletions: value })">
					{{ mirrorLabel }}
				</NcCheckboxRadioSwitch>

				<NcNoteCard v-if="service.status === 'error' && service.lastError" type="error" :data-test="'error-' + service.service">
					{{ service.lastError }}
				</NcNoteCard>
				<NcNoteCard v-else-if="service.lastError" type="warning" :data-test="'warning-' + service.service">
					{{ service.lastError }}
				</NcNoteCard>
				<NcNoteCard v-if="!service.viewerEnabled" type="info">
					{{ t('apple_sync', 'The {app} app is not enabled on this Nextcloud, so you cannot browse these yet. They are synced anyway.', { app: viewerName }) }}
				</NcNoteCard>

				<div class="service__actions">
					<NcButton v-if="!looping"
						:disabled="!!busy || !service.connected"
						:data-test="'sync-' + service.service"
						@click="syncNow">
						<template #icon>
							<NcLoadingIcon v-if="busy === 'sync'" :size="20" />
						</template>
						{{ t('apple_sync', 'Sync now') }}
					</NcButton>
					<NcButton v-else :data-test="'stop-' + service.service" @click="stop">
						{{ stopping ? t('apple_sync', 'Stopping…') : t('apple_sync', 'Stop') }}
					</NcButton>
					<NcButton variant="tertiary" :disabled="!!busy || syncing" :data-test="'reset-' + service.service" @click="reset">
						{{ t('apple_sync', 'Check everything again') }}
					</NcButton>
				</div>
			</template>
		</div>
		<NcNoteCard v-if="error" type="error" :data-test="'request-error-' + service.service">
			{{ error }}
		</NcNoteCard>
	</div>
</template>

<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { getFilePickerBuilder, showConfirmation } from '@nextcloud/dialogs'
import { n, t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { api, ApiError } from '../services/api.js'
import { formatBytes, formatRelative } from '../services/format.js'

const service = defineModel('service', { type: Object, required: true })

const busy = ref('')
const error = ref('')
const looping = ref(false)
const stopping = ref(false)
let unmounted = false

onBeforeUnmount(() => {
	unmounted = true
})

const TITLES = {
	contacts: t('apple_sync', 'Contacts'),
	calendars: t('apple_sync', 'Calendars'),
	drive: t('apple_sync', 'iCloud Drive'),
	photos: t('apple_sync', 'Photos'),
}
const VIEWERS = {
	contacts: t('apple_sync', 'Contacts'),
	calendar: t('apple_sync', 'Calendar'),
	photos: t('apple_sync', 'Photos'),
	files: t('apple_sync', 'Files'),
}

const title = computed(() => TITLES[service.value.service] ?? service.value.service)
const viewerName = computed(() => VIEWERS[service.value.viewerApp] ?? service.value.viewerApp)
const hasTarget = computed(() => ['drive', 'photos'].includes(service.value.service))
const syncing = computed(() => looping.value || service.value.running)

const where = computed(() => {
	switch (service.value.service) {
	case 'contacts':
		return t('apple_sync', 'Into the address book “iCloud”. iCloud groups become contact groups.')
	case 'calendars':
		return t('apple_sync', 'One Nextcloud calendar per iCloud calendar, named “… (iCloud)”.')
	case 'drive':
		return t('apple_sync', 'All files and folders of iCloud Drive, into {folder}.', { folder: service.value.target })
	case 'photos':
		return t('apple_sync', 'Original photos and videos, into {folder}/year/month. Live Photos also get their video.', { folder: service.value.target })
	}
	return ''
})

const mirrorLabel = computed(() => service.value.service === 'contacts' || service.value.service === 'calendars'
	? t('apple_sync', 'Also delete in Nextcloud what is deleted in iCloud')
	: t('apple_sync', 'Also delete in Nextcloud what is deleted in iCloud (to the Nextcloud trash bin)'))

const summary = computed(() => {
	const s = service.value
	if (!s.enabled) {
		return t('apple_sync', 'Off')
	}
	const progress = s.bytesDone > 0
		? t('apple_sync', '{items} so far ({size})', { items: itemCount(s.itemsDone), size: formatBytes(s.bytesDone) })
		: t('apple_sync', '{items} so far', { items: itemCount(s.itemsDone) })
	if (syncing.value) {
		return t('apple_sync', 'Syncing… {progress}', { progress })
	}
	if (s.status === 'pending') {
		return t('apple_sync', 'Paused, continues in the background. {progress}', { progress })
	}
	if (s.status === 'error') {
		return t('apple_sync', 'Failed')
	}
	if (s.lastSuccessAt) {
		return t('apple_sync', 'Synced {when} · {items}', { when: formatRelative(s.lastSuccessAt), items: itemCount(s.itemCount) })
	}
	return t('apple_sync', 'Not synced yet')
})

function itemCount(count) {
	switch (service.value.service) {
	case 'contacts':
		return n('apple_sync', '%n contact', '%n contacts', count)
	case 'calendars':
		return n('apple_sync', '%n event', '%n events', count)
	case 'photos':
		return n('apple_sync', '%n photo or video', '%n photos and videos', count)
	default:
		return n('apple_sync', '%n file', '%n files', count)
	}
}

async function request(name, fn) {
	busy.value = name
	error.value = ''
	try {
		return await fn()
	} catch (e) {
		if (e instanceof ApiError) {
			error.value = e.message
			return null
		}
		throw e
	} finally {
		busy.value = ''
	}
}

async function update(changes) {
	const result = await request('update', () => api.updateService(service.value.service, changes))
	if (result) {
		service.value = result
	}
	return result
}

async function toggle(enabled) {
	const result = await update({ enabled })
	if (result && enabled && result.connected) {
		syncNow()
	}
}

async function chooseFolder() {
	let chosen = null
	const picker = getFilePickerBuilder(t('apple_sync', 'Choose the folder for {name}', { name: title.value }))
		.setMultiSelect(false)
		.allowDirectories(true)
		.addMimeTypeFilter('httpd/unix-directory')
		.addButton({
			label: t('apple_sync', 'Use this folder'),
			variant: 'primary',
			callback: (nodes) => {
				chosen = nodes[0]?.path ?? null
			},
		})
		.build()
	try {
		await picker.pick()
	} catch {
		return // closed
	}
	if (!chosen || chosen === service.value.target) {
		return
	}
	if (chosen === '/') {
		error.value = t('apple_sync', 'Choose a folder inside your files, not the top level.')
		return
	}
	await update({ target: chosen })
}

function sleep(ms) {
	return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Run sync steps until the service is in sync, the user stops, or the page closes.
 * Each step runs about 20 seconds on the server.
 */
async function syncNow() {
	looping.value = true
	stopping.value = false
	error.value = ''
	let failures = 0
	try {
		while (!stopping.value && !unmounted) {
			let result
			try {
				result = await api.runService(service.value.service)
				failures = 0
			} catch (e) {
				// A long download can outlast a proxy timeout while the server keeps going.
				if (e instanceof ApiError && (e.status === undefined || e.status >= 502) && ++failures < 4) {
					await sleep(5000)
					continue
				}
				error.value = e instanceof ApiError ? e.message : String(e)
				return
			}
			service.value = result.service
			if (result.outcome === 'busy') {
				await sleep(5000)
				continue
			}
			if (result.outcome !== 'partial') {
				return
			}
		}
	} finally {
		looping.value = false
		stopping.value = false
	}
}

function stop() {
	stopping.value = true
}

async function reset() {
	const confirmed = await showConfirmation({
		name: t('apple_sync', 'Check everything again?'),
		text: t('apple_sync', 'The next sync compares everything in iCloud with Nextcloud instead of only what changed. Only what is missing or different is downloaded.'),
		labelConfirm: t('apple_sync', 'Check again'),
		labelReject: t('apple_sync', 'Cancel'),
	})
	if (!confirmed) {
		return
	}
	const result = await request('reset', () => api.resetService(service.value.service))
	if (result) {
		service.value = result
		syncNow()
	}
}
</script>

<style scoped lang="scss">
.service {
	padding-block: 10px;
	border-bottom: 1px solid var(--color-border);

	&:last-child {
		border-bottom: none;
	}

	&__head {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 4px 16px;
	}

	&__name {
		font-weight: bold;
	}

	&__summary {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		color: var(--color-text-maxcontrast);
	}

	&__body {
		padding-inline-start: 44px;
	}

	&__where,
	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__hint {
		font-style: italic;
	}

	&__target {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px;
		margin-top: 4px;

		code {
			overflow-wrap: anywhere;
		}
	}

	&__label {
		color: var(--color-text-maxcontrast);
	}

	&__actions {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 6px;
	}
}
</style>
