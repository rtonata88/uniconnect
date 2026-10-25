import { expect, test } from '@playwright/test'
import { login, run } from './helpers'

/** E2E-2: two browser contexts chat in real time and see the ticks turn blue. */
test('real-time chat with typing indicator and read ticks', async ({ browser }) => {
  const id = run()
  const lecturer = await login(browser, 'jshikongo@uniconnect.test')
  const student = await login(browser, 'student2@uniconnect.test', { mobile: true })

  // student starts a private chat from the course People tab
  await student.page.goto('/courses')
  await student.page.getByRole('link', { name: /SE902/ }).click()
  await student.page.getByRole('tab', { name: 'People' }).click()
  await student.page.getByRole('button', { name: /Message Prof\. Johannes Shikongo/ }).click()
  await expect(student.page).toHaveURL(/\/chats\/\d+/)
  const chatUrl = new URL(student.page.url()).pathname

  await lecturer.page.goto(chatUrl)
  await expect(lecturer.page.getByRole('log')).toBeVisible()
  await expect(student.page.getByText('online')).toBeVisible() // presence of the lecturer

  // typing indicator (MSG-8)
  const box = student.page.getByLabel('Message', { exact: true })
  await box.pressSequentially(`Hello ${id}`, { delay: 40 })
  await expect(lecturer.page.getByText(/is typing/).first()).toBeVisible()
  await box.press('Enter')

  // delivered in real time, and the sender's ticks turn blue because the chat is open
  const onLecturer = lecturer.page.getByTestId('message').filter({ hasText: `Hello ${id}` })
  await expect(onLecturer).toBeVisible()
  const mine = student.page.getByTestId('message').filter({ hasText: `Hello ${id}` })
  await expect(mine.getByRole('img', { name: 'Read' })).toBeVisible()

  // reply comes back the other way
  await lecturer.page.getByLabel('Message', { exact: true }).fill(`Hi ${id}`)
  await lecturer.page.keyboard.press('Enter')
  await expect(student.page.getByTestId('message').filter({ hasText: `Hi ${id}` })).toBeVisible()
  await expect(lecturer.page.getByTestId('message').filter({ hasText: `Hi ${id}` }).getByRole('img', { name: 'Read' })).toBeVisible()

  await student.context.close()
  await lecturer.context.close()
})
