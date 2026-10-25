import { AxiosError } from 'axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { db } from '@/lib/db'
import { isOnline, setOnline } from '@/lib/useOnline'
import { NotAvailableOffline, repo, weightedTotal } from './repo'

describe('repository read path (PRD 9.5)', () => {
  beforeEach(async () => {
    vi.restoreAllMocks()
    await db.delete()
    await db.open()
    setOnline(true)
  })

  it('online: fetches, stores in IndexedDB and returns the server data', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [{ id: 2, code: 'B' }, { id: 1, code: 'A' }] } } as never)
    const list = await repo.getCourses()
    expect(list.map((c) => c.id)).toEqual([2, 1])
    expect(await db.courses.count()).toBe(2)
  })

  it('offline: answers from IndexedDB without calling the API', async () => {
    await db.courses.bulkPut([{ id: 2, code: 'B' }, { id: 1, code: 'A' }] as never)
    setOnline(false)
    const get = vi.spyOn(api, 'get')
    const list = await repo.getCourses()
    expect(get).not.toHaveBeenCalled()
    expect(list.map((c) => c.code)).toEqual(['A', 'B'])
  })

  it('falls back to the local copy when the request fails without a response, and flips to offline', async () => {
    await db.courses.put({ id: 1, code: 'A' } as never)
    await db.materials.bulkPut([{ id: 5, course_id: 1, week: 2 }, { id: 4, course_id: 1, week: 1 }] as never)
    vi.spyOn(api, 'get').mockRejectedValue(new AxiosError('Network Error', 'ERR_NETWORK'))
    const materials = await repo.getMaterials(1)
    expect(materials.map((m) => m.id)).toEqual([4, 5])
    expect(isOnline()).toBe(false)
  })

  it('reports data that was never saved on this device', async () => {
    setOnline(false)
    await expect(repo.getCourse(42)).rejects.toBeInstanceOf(NotAvailableOffline)
  })

  it('shows a submission still waiting in the outbox as pending', async () => {
    await db.courses.put({ id: 1, code: 'A' } as never)
    await db.assignments.put({ id: 3, course_id: 1, due_at: '2026-11-01T00:00:00Z' } as never)
    await db.outbox.add({ client_uuid: 'u', type: 'submission', payload: { assignment_id: 3, course_id: 1, text: 'draft', client_submitted_at: '2026-10-25T10:00:00Z' }, created_at: 1, attempts: 0, status: 'pending' })
    setOnline(false)
    const [a] = await repo.getAssignments(1)
    expect(a.my_submission?.pending).toBe(true)
    expect(a.my_submission?.text).toBe('draft')
  })

  it('computes the weighted total like the server', () => {
    const g = (score: number | null, max: number, w: number) => ({ max_score: max, weight_percent: w, grade: score === null ? null : { score } })
    expect(weightedTotal([g(50, 50, 30), g(25, 50, 70)])).toBe(65)
    expect(weightedTotal([g(40, 50, 0), g(20, 50, 0)])).toBe(60)
    expect(weightedTotal([g(null, 50, 50)])).toBeNull()
  })
})
