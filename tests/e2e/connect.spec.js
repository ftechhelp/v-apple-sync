import { expect, test } from '@playwright/test'

// The settings page connect flow, against tests/e2e/fake-apple.php.
const FAKE_APPLE = process.env.E2E_FAKE_APPLE ?? 'http://localhost:8092'
const USER = 'admin'
const PASSWORD = 'admin-e2e-pass'

async function login(page) {
	await page.goto('/login')
	await page.locator('#user').fill(USER)
	await page.locator('#password').fill(PASSWORD)
	await page.locator('button[type=submit]').click()
	await page.waitForURL((url) => !url.pathname.includes('/login'))
}

async function openSettings(page) {
	await page.goto('/settings/user/apple_sync')
	const panel = page.locator('[data-test="web-connect"]')
	await expect(panel).toBeVisible()
	return panel
}

async function disconnectIfConnected(panel, page) {
	if (await panel.locator('[data-test="disconnect"]').isVisible()) {
		await panel.locator('[data-test="disconnect"]').click()
		await page.getByRole('dialog').getByRole('button', { name: 'Disconnect' }).click()
		await expect(panel.locator('[data-test="connect-form"]')).toBeVisible()
	}
}

test.beforeEach(async ({ page, request }) => {
	await request.post(`${FAKE_APPLE}/__control/reset`)
	await login(page)
	await disconnectIfConnected(await openSettings(page), page)
})

test('connect with a device code, check, reconnect by text message, disconnect', async ({ page, request }) => {
	const panel = await openSettings(page)
	await panel.locator('[data-test="apple-id"]').fill('me@example.com')
	await panel.locator('[data-test="password"]').fill('correct horse')
	await panel.locator('[data-test="connect"]').click()

	const code = panel.locator('[data-test="code"]')
	await expect(code).toBeVisible()
	await code.fill('000000') // six digits submit by themselves
	await expect(panel.locator('[data-test="error"]')).toContainText('not accepted')
	await code.fill('123456')
	await expect(panel).toContainText('Connected as me@example.com')

	// The state comes from the server, not the page.
	await page.reload()
	await expect(panel).toContainText('Connected as me@example.com')
	await expect(panel).toContainText('Next two-factor code needed')

	await panel.locator('[data-test="check"]').click()
	await expect(panel.locator('[data-test="info"]')).toContainText('The connection works')

	// About a month later Apple forgets the trust token.
	await request.post(`${FAKE_APPLE}/__control/expire`)
	await panel.locator('[data-test="check"]').click()
	await expect(panel.locator('[data-test="reauth"]')).toBeVisible()

	// Reconnect reuses the stored password and only asks for a code.
	await panel.locator('[data-test="reconnect"]').click()
	await expect(code).toBeVisible()
	await panel.locator('[data-test="text-me"]').click()
	await panel.getByText('+33 •• •• •• •• 17').click()
	await panel.locator('[data-test="send-sms"]').click()
	await expect(panel).toContainText('Enter the code sent by text message to +33')
	await code.fill('654321')
	await expect(panel).toContainText('Connected as me@example.com')

	await panel.locator('[data-test="disconnect"]').click()
	await page.getByRole('dialog').getByRole('button', { name: 'Disconnect' }).click()
	await expect(panel.locator('[data-test="connect-form"]')).toBeVisible()
	await page.reload()
	await expect(panel.locator('[data-test="connect-form"]')).toBeVisible()
})

test('a wrong password shows Apple\'s error and stays on the form', async ({ page }) => {
	const panel = await openSettings(page)
	await panel.locator('[data-test="apple-id"]').fill('wrong@example.com')
	await panel.locator('[data-test="password"]').fill('nope')
	await panel.locator('[data-test="connect"]').click()
	await expect(panel.locator('[data-test="error"]')).toContainText('Incorrect Apple ID or password')
	await expect(panel.locator('[data-test="connect-form"]')).toBeVisible()
})

test('cancelling the code step returns to the form', async ({ page }) => {
	const panel = await openSettings(page)
	await panel.locator('[data-test="apple-id"]').fill('me@example.com')
	await panel.locator('[data-test="password"]').fill('correct horse')
	await panel.locator('[data-test="connect"]').click()
	await expect(panel.locator('[data-test="code"]')).toBeVisible()

	// A pending sign-in survives a reload.
	await page.reload()
	await expect(panel.locator('[data-test="code"]')).toBeVisible()

	await panel.locator('[data-test="cancel"]').click()
	await expect(panel.locator('[data-test="connect-form"]')).toBeVisible()
})
