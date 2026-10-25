import { expect, test } from '@playwright/test'
import { login } from './helpers'

/** E2E-4: a student cannot open a course they are not enrolled in. */
test('student cannot open a course they are not enrolled in', async ({ browser }) => {
  // student7 takes DMS901 only; SE902 is seeded second
  const { context, page } = await login(browser, 'student7@uniconnect.test')
  const courses = await page.request.get('/api/courses', { headers: { Authorization: `Bearer ${await token(page)}` } })
  const mine = (await courses.json()).data.map((c: { code: string }) => c.code)
  expect(mine).toEqual(['DMS901'])

  const se902 = await findCourseId(page, 'SE902')
  await page.goto(`/courses/${se902}`)
  await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible()
  await expect(page.getByText('You are not enrolled in this course.')).toBeVisible()

  const api = await page.request.get(`/api/courses/${se902}/materials`, { headers: { Authorization: `Bearer ${await token(page)}`, Accept: 'application/json' } })
  expect(api.status()).toBe(403)
  await context.close()
})

async function token(page: import('@playwright/test').Page): Promise<string> {
  return page.evaluate(
    () =>
      new Promise<string>((resolve) => {
        const open = indexedDB.open('uniconnect')
        open.onsuccess = () => {
          const req = open.result.transaction('meta').objectStore('meta').get('token')
          req.onsuccess = () => resolve(req.result.value)
        }
      }),
  )
}

/** As an admin would see it: course ids depend on seeding order. */
async function findCourseId(page: import('@playwright/test').Page, code: string): Promise<number> {
  const res = await page.request.post('/api/auth/login', { data: { email: 'admin@uniconnect.test', password: 'Password123!' } })
  const adminToken = (await res.json()).token
  const list = await page.request.get('/api/courses', { headers: { Authorization: `Bearer ${adminToken}` } })
  return (await list.json()).data.find((c: { code: string; id: number }) => c.code === code).id
}
