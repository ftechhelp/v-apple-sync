import { getLanguage, t } from '@nextcloud/l10n'

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB']

export function formatBytes(bytes) {
	let value = Number(bytes) || 0
	let unit = 0
	while (value >= 1024 && unit < UNITS.length - 1) {
		value /= 1024
		unit++
	}
	const digits = unit === 0 || value >= 100 ? 0 : 1
	return `${value.toLocaleString(getLanguage(), { maximumFractionDigits: digits })} ${UNITS[unit]}`
}

/**
 * "5 minutes ago", "yesterday"... from unix seconds.
 */
export function formatRelative(seconds) {
	const diff = Math.round(seconds - Date.now() / 1000)
	const abs = Math.abs(diff)
	if (abs < 60) {
		return t('apple_sync', 'just now')
	}
	const format = new Intl.RelativeTimeFormat(getLanguage(), { numeric: 'auto' })
	for (const [unit, size] of [['day', 86400], ['hour', 3600], ['minute', 60]]) {
		if (abs >= size) {
			return format.format(Math.round(diff / size), unit)
		}
	}
	return format.format(diff, 'second')
}

export function formatDate(seconds) {
	return new Date(seconds * 1000).toLocaleString(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' })
}
