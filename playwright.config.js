import { defineConfig } from '@playwright/test'

// Browser tests against the throwaway stack from tests/e2e/setup.sh.
export default defineConfig({
	testDir: 'tests/e2e',
	timeout: 60_000,
	workers: 1,
	reporter: 'list',
	outputDir: 'build/e2e-results',
	use: {
		baseURL: process.env.E2E_URL ?? 'http://localhost:8091',
		locale: 'en-US',
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
	},
})
