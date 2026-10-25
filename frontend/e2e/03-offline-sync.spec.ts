import { expect, test } from '@playwright/test'
import { apiAs, login, openCourse, run } from './helpers'

/**
 * E2E-3: go offline, send 3 messages and a submission, go online, verify they sync
 * (in order, no duplicates).
 */
test('offline messages and submission sync when back online', async ({ browser }) => {
  const id = run()
  const student = await login(browser, 'student10@uniconnect.test', { mobile: true })
  const page = student.page
  // a fresh assignment for this run (setup through the API as the lecturer)
  const lecturerApi = await apiAs(page, 'mziezo@uniconnect.test')
  const course = (await lecturerApi.get('/courses')).data.find((c: { code: string }) => c.code === 'DMS901')
  const title = `Offline task ${id}`
  await lecturerApi.post(`/courses/${course.id}/assignments`, { title, due_at: new Date(Date.now() + 3 * 864e5).toISOString(), max_score: 10 })

  // visit what we will need offline so it is stored on the device
  await openCourse(page, 'DMS901', 'Assignments')
  await expect(page.getByRole('link', { name: new RegExp(title) })).toBeVisible()
  await page.getByRole('tab', { name: 'Chat' }).click()
  await expect(page.getByLabel('Message', { exact: true })).toBeVisible()
  await page.waitForTimeout(1500)

  // ---- offline
  await student.context.setOffline(true)
  await expect(page.getByText('Offline – changes will sync')).toBeVisible()
  const box = page.getByLabel('Message', { exact: true })
  for (const n of [1, 2, 3]) {
    await box.fill(`offline ${n} ${id}`)
    await box.press('Enter')
  }
  await expect(page.locator('[data-testid="message"][data-status="pending"]')).toHaveCount(3)

  await page.getByRole('tab', { name: 'Assignments' }).click()
  await page.getByRole('link', { name: new RegExp(title) }).click()
  await page.getByLabel('Answer').fill(`written offline ${id}`)
  await page.getByRole('button', { name: 'Submit' }).click()
  await expect(page.getByText('Waiting for a connection to send.')).toBeVisible()

  // ---- online again
  await student.context.setOffline(false)
  await expect(page.getByText('Waiting for a connection to send.')).toHaveCount(0, { timeout: 30_000 })
  await expect(page.getByText(`written offline ${id}`)).toBeVisible()

  // a lecturer sees the three messages once each, in order
  const lecturer = await login(browser, 'mziezo@uniconnect.test')
  await openCourse(lecturer.page, 'DMS901', 'Chat')
  const synced = lecturer.page.getByTestId('message').filter({ hasText: id })
  await expect(synced).toHaveCount(3)
  const texts = await synced.allInnerTexts()
  expect(texts.map((t) => t.match(/offline (\d)/)?.[1])).toEqual(['1', '2', '3'])

  // and the submission is in the submissions table
  await lecturer.page.getByRole('tab', { name: 'Assignments' }).click()
  await lecturer.page.getByRole('link', { name: new RegExp(title) }).click()
  await expect(lecturer.page.getByRole('row', { name: /Julia Mwafufya/ })).toContainText(/On time|Late/)

  await student.context.close()
  await lecturer.context.close()
})
