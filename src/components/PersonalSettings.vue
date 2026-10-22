<template>
	<div>
		<NcSettingsSection :name="t('apple_sync', 'Apple ID: Photos and iCloud Drive')"
			:description="t('apple_sync', 'Sign in with your Apple ID to sync your photos and iCloud Drive into Nextcloud. Sync is one-way: nothing is ever changed in iCloud.')">
			<WebConnect v-model:account="account" />
		</NcSettingsSection>

		<NcSettingsSection :name="t('apple_sync', 'App-specific password: Contacts and Calendars')"
			:description="t('apple_sync', 'Apple only gives out contacts and calendars over CardDAV and CalDAV, which need an app-specific password instead of your Apple ID password.')">
			<DavConnect v-model:account="account" />
		</NcSettingsSection>

		<NcSettingsSection :name="t('apple_sync', 'What to sync')"
			:description="t('apple_sync', 'Turned-on services sync by themselves every 5 minutes. Changes made in Nextcloud to synced items are overwritten by the iCloud version.')">
			<SyncServices v-model:sync="sync" :account="account" />
		</NcSettingsSection>
	</div>
</template>

<script setup>
import { ref } from 'vue'
import { loadState } from '@nextcloud/initial-state'
import { t } from '@nextcloud/l10n'
import NcSettingsSection from '@nextcloud/vue/components/NcSettingsSection'
import DavConnect from './DavConnect.vue'
import SyncServices from './SyncServices.vue'
import WebConnect from './WebConnect.vue'

const account = ref(loadState('apple_sync', 'account'))
const sync = ref(loadState('apple_sync', 'sync', { services: [], cron: null }))
</script>
