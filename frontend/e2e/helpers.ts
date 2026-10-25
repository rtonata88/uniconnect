import { expect, type Browser, type BrowserContext, type Page } from '@playwright/test'

export const PASSWORD = 'Password123!'
export const run = () => String(Date.now()).slice(-6)

export async function login(browser: Browser, email: string, opts: { mobile?: boolean } = {}): Promise<{ context: BrowserContext; page: Page }> {
  const context = await browser.newContext(
    opts.mobile ? { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true } : { viewport: { width: 1280, height: 860 } },
  )
  const page = await context.newPage()
  await page.goto('/login')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(PASSWORD)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page).toHaveURL(/\/$/)
  return { context, page }
}

/** Call the API as a given user (test setup). */
export async function apiAs(page: Page, email: string) {
  const res = await page.request.post('/api/auth/login', { data: { email, password: PASSWORD, device_name: 'e2e' } })
  const token = (await res.json()).token as string
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' }
  return {
    get: async (url: string) => (await page.request.get(`/api${url}`, { headers })).json(),
    post: async (url: string, data: unknown) => (await page.request.post(`/api${url}`, { headers, data })).json(),
  }
}

/** Open a course from the Courses list by its code (ids depend on seeding order). */
export async function openCourse(page: Page, code: string, tab?: string) {
  await page.goto('/courses')
  await page.getByRole('link', { name: new RegExp(code) }).first().click()
  await expect(page.getByRole('heading', { level: 1 })).toBeVisible()
  if (tab) await page.getByRole('tab', { name: tab }).click()
}
