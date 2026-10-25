import { defineConfig, devices } from '@playwright/test'

/**
 * End-to-end tests against the running stack (make up && make seed), through the
 * Traefik gateway exactly like a user. E2E_BASE_URL overrides https://localhost.
 */
export default defineConfig({
  testDir: './e2e',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list'], ['junit', { outputFile: '../docs/testing/results/e2e-junit.xml' }], ['html', { open: 'never', outputFolder: 'playwright-report' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'https://localhost',
    ignoreHTTPSErrors: true,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    launchOptions: { args: ['--ignore-certificate-errors'] },
  },
  projects: [
    { name: 'desktop-chrome', use: { ...devices['Desktop Chrome'] } },
  ],
})
