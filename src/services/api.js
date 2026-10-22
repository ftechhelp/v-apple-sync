import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/**
 * Error from the app's API. `account` is the account status the server sent
 * along with the error (if any), so the UI can follow state changes.
 */
export class ApiError extends Error {
	constructor(message, status, account) {
		super(message)
		this.status = status
		this.account = account
	}
}

async function call(method, path, data) {
	try {
		const response = await axios({ method, url: generateUrl('/apps/apple_sync/api' + path), data })
		return response.data
	} catch (error) {
		const status = error.response?.status
		const body = error.response?.data
		const account = body && typeof body === 'object' && 'webState' in body ? body : null
		let message = body?.message
		if (!message) {
			message = status === 429
				? t('apple_sync', 'Too many attempts. Wait a few minutes and try again.')
				: t('apple_sync', 'Something went wrong. Please try again.')
		}
		throw new ApiError(message, status, account)
	}
}

export const api = {
	status: () => call('get', '/status'),
	connect: (appleId, password) => call('post', '/web/connect', { appleId, password }),
	verify: (code, phoneId = null) => call('post', '/web/verify', { code, phoneId }),
	resend: () => call('post', '/web/resend'),
	phones: () => call('get', '/web/phones'),
	sms: (phoneId) => call('post', '/web/sms', { phoneId }),
	cancel: () => call('post', '/web/cancel'),
	check: () => call('post', '/web/check'),
	disconnect: () => call('delete', '/web'),

	connectDav: (appleId, password) => call('post', '/dav/connect', { appleId, password }),
	disconnectDav: () => call('delete', '/dav'),

	sync: () => call('get', '/sync'),
	updateService: (service, changes) => call('put', `/sync/${service}`, changes),
	runService: (service) => call('post', `/sync/${service}/run`),
	resetService: (service) => call('post', `/sync/${service}/reset`),
}
