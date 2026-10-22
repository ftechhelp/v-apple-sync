<template>
	<div class="sync-services" data-test="sync-services">
		<NcNoteCard v-if="sync.cron?.stale" type="warning" data-test="cron-warning">
			<p>
				{{ sync.cron.lastRun
					? t('apple_sync', 'Nextcloud’s background jobs last ran {when}, so nothing syncs by itself right now.', { when: formatDate(sync.cron.lastRun) })
					: t('apple_sync', 'Nextcloud’s background jobs have never run, so nothing syncs by itself right now.') }}
				{{ t('apple_sync', '“Sync now” works anyway while this page is open.') }}
			</p>
			<p class="sync-services__detail">
				{{ t('apple_sync', 'For automatic syncing, an administrator sets Background jobs to “Cron” in Administration settings → Basic settings and runs cron.php every 5 minutes.') }}
			</p>
		</NcNoteCard>

		<ServiceCard v-for="(service, index) in sync.services"
			:key="service.service"
			:service="service"
			@update:service="(value) => replace(index, value)" />

		<p class="sync-services__later">
			{{ t('apple_sync', 'Reminders are coming in a later version.') }}
		</p>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ServiceCard from './ServiceCard.vue'
import { api } from '../services/api.js'
import { formatDate } from '../services/format.js'

const props = defineProps({
	account: { type: Object, required: true },
})
const sync = defineModel('sync', { type: Object, required: true })

function replace(index, value) {
	const services = [...sync.value.services]
	services[index] = value
	sync.value = { ...sync.value, services }
}

async function refresh() {
	try {
		sync.value = await api.sync()
	} catch {
		// keep what is shown
	}
}

// Connecting or disconnecting changes which services can run.
watch(() => [props.account.webState, props.account.davState], refresh)

// While background jobs work on something, keep its progress current.
let timer = null
onMounted(() => {
	timer = setInterval(() => {
		if (document.visibilityState === 'visible' && sync.value.services.some((s) => s.running || s.status === 'pending')) {
			refresh()
		}
	}, 10000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped lang="scss">
.sync-services {
	max-width: 760px;

	&__detail,
	&__later {
		color: var(--color-text-maxcontrast);
	}

	&__later {
		margin-top: 12px;
	}
}
</style>
