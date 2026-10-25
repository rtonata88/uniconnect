import { expect, test } from '@playwright/test'
import { login } from './helpers'

/** MOB-2 installable PWA that opens offline; MOB-1/MOB-3 phone layout and touch targets. */
test('MOB-2 the app is installable and opens with no network', async ({ browser }) => {
  const context = await browser.newContext()
  const page = await context.newPage()
  await page.goto('/login')
  const manifest = await (await page.request.get('/manifest.webmanifest')).json()
  expect(manifest.display).toBe('standalone')
  expect(manifest.icons.some((i: { purpose?: string }) => i.purpose === 'maskable')).toBe(true)
  await page.waitForFunction(async () => !!(await navigator.serviceWorker.ready).active)
  const cdp = await context.newCDPSession(page)
  const { installabilityErrors } = await cdp.send('Page.getInstallabilityErrors')
  expect(installabilityErrors).toEqual([])
  // app shell from the service worker cache
  await page.reload() // make sure the page is controlled
  await context.setOffline(true)
  await page.reload()
  await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible()
  await context.close()
})

test('MOB-1 / MOB-3 phone layout: bottom tabs, 44 px touch targets, camera capture', async ({ browser }) => {
  const { context, page } = await login(browser, 'student1@uniconnect.test', { mobile: true })
  const tabs = page.getByRole('navigation', { name: 'Main' }).getByRole('link')
  await expect(tabs).toHaveCount(4)
  for (const box of await tabs.evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height))) expect(box).toBeGreaterThanOrEqual(44)
  // desktop sidebar is hidden on phones
  await expect(page.locator('[data-slot="sidebar"]').first()).toBeHidden()
  // camera / file picker for submissions and chat attachments
  await page.goto('/chats')
  await page.getByRole('link').filter({ hasText: 'DMS901' }).first().click()
  await expect(page.locator('input[type="file"][capture]')).toHaveCount(1)
  await context.close()
})
