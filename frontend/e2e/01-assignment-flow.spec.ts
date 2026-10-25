import { expect, test } from '@playwright/test'
import { login, openCourse, run } from './helpers'

/**
 * E2E-1: lecturer creates an assignment → student gets a notification → submits →
 * lecturer grades → (releases) → student sees the released grade.
 */
test('assignment from creation to released grade', async ({ browser }) => {
  const id = run()
  const title = `E2E essay ${id}`
  const student = await login(browser, 'student8@uniconnect.test', { mobile: true })
  const lecturer = await login(browser, 'mziezo@uniconnect.test')

  // lecturer creates the assignment in DMS901
  await openCourse(lecturer.page, 'DMS901', 'Assignments')
  await lecturer.page.getByRole('button', { name: 'New assignment' }).click()
  await lecturer.page.getByLabel('Title').fill(title)
  await lecturer.page.getByLabel('Instructions').fill('Explain replication in one paragraph.')
  await lecturer.page.getByLabel('Max score').fill('20')
  await lecturer.page.getByRole('button', { name: 'Save' }).click()
  await expect(lecturer.page.getByRole('link', { name: new RegExp(title) })).toBeVisible()

  // the student is notified in real time (toast + bell)
  await expect(student.page.getByText('New assignment in DMS901').first()).toBeVisible()
  await expect(student.page.getByRole('button', { name: /Notifications, \d+ unread/ })).toBeVisible()

  // student opens it from the notification centre and submits
  await student.page.goto('/notifications')
  await student.page.getByRole('button', { name: new RegExp(title) }).first().click()
  await expect(student.page.getByRole('heading', { name: title })).toBeVisible()
  await student.page.getByLabel('Answer').fill(`Primary/replica with GTID ${id}`)
  await student.page.getByRole('button', { name: 'Submit' }).click()
  await expect(student.page.getByText('Submitted', { exact: false }).first()).toBeVisible()

  // lecturer grades it
  await lecturer.page.getByRole('link', { name: new RegExp(title) }).click()
  await lecturer.page.getByRole('row', { name: /Hilma Nambahu/ }).click()
  await lecturer.page.getByLabel(/Score/).fill('17')
  await lecturer.page.getByLabel('Feedback').fill('Clear and correct.')
  await lecturer.page.getByRole('button', { name: 'Save grade' }).click()
  await expect(lecturer.page.getByRole('row', { name: /Hilma Nambahu/ })).toContainText('17 / 20')

  // not visible to the student before release
  await student.page.reload()
  await expect(student.page.getByText('Grade: 17')).toHaveCount(0)

  // release in the gradebook
  await openCourse(lecturer.page, 'DMS901', 'Grades')
  const column = lecturer.page.getByRole('columnheader', { name: new RegExp(title) })
  await column.getByRole('button', { name: 'Release' }).click()
  await expect(column.getByRole('button', { name: 'Released' })).toBeVisible()

  // the student sees the grade (and is notified)
  await expect(student.page.getByText(/Grade released in DMS901/).first()).toBeVisible()
  await student.page.reload()
  await expect(student.page.getByText('Grade: 17 / 20')).toBeVisible()
  await expect(student.page.getByText('Clear and correct.')).toBeVisible()

  await student.context.close()
  await lecturer.context.close()
})
