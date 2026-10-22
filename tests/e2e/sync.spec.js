import { expect, test } from '@playwright/test'

// Turning services on in the settings page and syncing them from the fake
// iCloud (tests/Support/FakeICloud.php, seeded in tests/e2e/fake-apple.php).
// Each run uses a new user, deleted at the end, so runs do not see each other.
const FAKE_APPLE = process.env.E2E_FAKE_APPLE ?? 'http://localhost:8092'
const ADMIN = { Authorization: 'Basic ' + Buffer.from('admin:admin-e2e-pass').toString('base64'), 'OCS-APIRequest': 'true' }
const APP_PASSWORD = 'abcd-efgh-ijkl-mnop'
const USER = 'sync-' + Date.now()
const PASSWORD = 'Sync-e2e-' + Math.random().toString(36).slice(2) + '-pass'
const AS_USER = { Authorization: 'Basic ' + Buffer.from(`${USER}:${PASSWORD}`).toString('base64') }

test.describe.configure({ mode: 'serial' })

test.beforeAll(async ({ request }) => {
	await request.post(`${FAKE_APPLE}/__control/reset`)
	const created = await request.post('/ocs/v2.php/cloud/users?format=json', { headers: ADMIN, form: { userid: USER, password: PASSWORD } })
	expect(created.ok()).toBeTruthy()
})

test.afterAll(async ({ request }) => {
	// Also runs the app's UserDeletedEvent listener, which removes its rows.
	await request.delete(`/ocs/v2.php/cloud/users/${USER}?format=json`, { headers: ADMIN })
})

async function icloud(request, call, ...args) {
	const response = await request.post(`${FAKE_APPLE}/__control/icloud`, { data: { call, args } })
	expect(response.status()).toBe(204)
}

async function file(request, path) {
	const response = await request.get(`/remote.php/dav/files/${USER}/${path.split('/').map(encodeURIComponent).join('/')}`, { headers: AS_USER })
	return response.ok() ? response.text() : null
}

function toggle(page, service) {
	return page.locator(`[data-test="service-${service}"]`).getByRole('switch').first()
}

const TITLES = { contacts: 'Contacts', calendars: 'Calendars', drive: 'iCloud Drive', photos: 'Photos' }

async function turnOn(page, service) {
	// The switch's label covers its input.
	await page.locator(`[data-test="service-${service}"]`).getByText(TITLES[service], { exact: true }).click()
	await expect(toggle(page, service)).toBeChecked()
}

test('sign in twice, turn everything on, sync, and pick up changes', async ({ page, request }) => {
	await page.goto('/login')
	await page.locator('#user').fill(USER)
	await page.locator('#password').fill(PASSWORD)
	await page.locator('button[type=submit]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
	await page.goto('/settings/user/apple_sync')

	// Nothing can be turned on before signing in.
	await expect(toggle(page, 'contacts')).toBeDisabled()

	const web = page.locator('[data-test="web-connect"]')
	await web.locator('[data-test="apple-id"]').fill('me@example.com')
	await web.locator('[data-test="password"]').fill('correct horse')
	await web.locator('[data-test="connect"]').click()
	await web.locator('[data-test="code"]').fill('123456')
	await expect(web).toContainText('Connected as me@example.com')

	const dav = page.locator('[data-test="dav-connect"]')
	await dav.locator('[data-test="dav-apple-id"]').fill('me@example.com')
	await dav.locator('[data-test="dav-password"]').fill('wrong-pass-word')
	await dav.locator('[data-test="dav-connect"]').click()
	await expect(dav.locator('[data-test="dav-error"]')).toBeVisible()
	await dav.locator('[data-test="dav-password"]').fill(APP_PASSWORD.replaceAll('-', ' - '))
	await dav.locator('[data-test="dav-connect"]').click()
	await expect(dav.locator('[data-test="dav-disconnect"]')).toBeVisible()

	// Turning a service on syncs it right away.
	await turnOn(page, 'contacts')
	await expect(page.locator('[data-test="summary-contacts"]')).toContainText('3 contacts', { timeout: 30_000 })
	await turnOn(page, 'calendars')
	await expect(page.locator('[data-test="summary-calendars"]')).toContainText('3 events', { timeout: 30_000 })
	await turnOn(page, 'drive')
	await expect(page.locator('[data-test="summary-drive"]')).toContainText('2 files', { timeout: 30_000 })
	await expect(page.locator('[data-test="target-drive"]')).toHaveText('/iCloud Drive')
	await turnOn(page, 'photos')
	await expect(page.locator('[data-test="summary-photos"]')).toContainText('3 photos and videos', { timeout: 30_000 })

	// What arrived in Nextcloud.
	expect(await file(request, 'iCloud Drive/Notes/todo.txt')).toBe('buy milk')
	expect(await file(request, 'iCloud Drive/Read me.md')).toBe('# hello')
	expect(await file(request, 'iCloud Photos/2024/05/IMG_0001.HEIC')).toBe('photo-1')
	expect(await file(request, 'iCloud Photos/2024/05/IMG_0001.MOV')).toBe('video-1')
	expect(await file(request, 'iCloud Photos/2025/03/IMG_0002.JPG')).toBe('photo-2')

	const anna = await request.get(`/remote.php/dav/addressbooks/users/${USER}/icloud/anna.vcf`, { headers: AS_USER })
	expect(anna.ok()).toBeTruthy()
	expect(await anna.text()).toContain('CATEGORIES:Family')

	const calendars = await request.fetch(`/remote.php/dav/calendars/${USER}/`, {
		method: 'PROPFIND',
		headers: { ...AS_USER, Depth: '1', 'Content-Type': 'application/xml' },
		data: '<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>',
	})
	const listing = await calendars.text()
	expect(listing).toContain('Home (iCloud)')
	expect(listing).toContain('Work (iCloud)')

	// Changes in iCloud arrive with the next sync.
	await icloud(request, 'addContact', 'dora', 'Dora Date')
	await icloud(request, 'changeFile', 'FILE::com.apple.CloudDocs::todo', 'buy oat milk')
	await icloud(request, 'addPhoto', '3', 'IMG_0003.PNG', '2025-03-20T09:00:00Z', 'photo-3')
	await page.locator('[data-test="sync-contacts"]').click()
	await expect(page.locator('[data-test="summary-contacts"]')).toContainText('4 contacts', { timeout: 30_000 })
	await page.locator('[data-test="sync-drive"]').click()
	await expect.poll(() => file(request, 'iCloud Drive/Notes/todo.txt'), { timeout: 30_000 }).toBe('buy oat milk')
	await page.locator('[data-test="sync-photos"]').click()
	await expect(page.locator('[data-test="summary-photos"]')).toContainText('4 photos and videos', { timeout: 30_000 })
	expect(await file(request, 'iCloud Photos/2025/03/IMG_0003.PNG')).toBe('photo-3')

	// The state comes from the server.
	await page.reload()
	await expect(page.locator('[data-test="summary-contacts"]')).toContainText('4 contacts')
	await expect(page.locator('[data-test="summary-drive"]')).toContainText('2 files')
})
