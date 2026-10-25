import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { db, getMeta, setMeta } from '@/lib/db'
import { setOnline } from '@/lib/useOnline'
import { useAuth } from '@/features/auth/store'
import { runSync, type SyncPayload } from './engine'

const page = (over: Partial<SyncPayload>): SyncPayload => ({ cursor: 'c', has_more: false, messages: [], deleted: {}, ...over })

describe('sync engine (PRD 9.3)', () => {
  beforeEach(async () => {
    vi.restoreAllMocks()
    await db.delete()
    await db.open()
    setOnline(true)
    useAuth.setState({ user: { id: 1, name: 'Me', email: 'me@u.test', role: 'student', notification_preferences: {} } })
  })

  it('loops while has_more, passing the cursor along, and stores the final cursor', async () => {
    const get = vi.spyOn(api, 'get').mockImplementation(async (_url, cfg) => {
      const since = (cfg?.params as { since?: string } | undefined)?.since
      const data =
        since === undefined
          ? page({ cursor: 'p1', has_more: true, courses: [{ id: 5, code: 'X' }] })
          : since === 'p1'
            ? page({ cursor: 'p2', has_more: true })
            : page({ cursor: 'final', has_more: false })
      return { data } as never
    })
    vi.spyOn(api, 'post').mockResolvedValue({ data: {} } as never)

    await runSync()
    expect(get.mock.calls.map((c) => (c[1]?.params as { since?: string }).since)).toEqual([undefined, 'p1', 'p2'])
    expect(await getMeta('sync_cursor')).toBe('final')
    expect(await db.courses.get(5)).toBeTruthy()
  })

  it('applies deletions and drops courses and chats the user left', async () => {
    await db.courses.bulkPut([{ id: 1, code: 'A' }, { id: 2, code: 'B' }])
    await db.materials.bulkPut([{ id: 10, course_id: 1 }, { id: 11, course_id: 2 }])
    await db.conversations.put({ id: 7, title: 'old' })
    await db.messages.put({ client_uuid: 'm', id: 99, conversation_id: 7 })
    await setMeta('sync_cursor', 'c0')
    vi.spyOn(api, 'get').mockResolvedValue({
      data: page({ cursor: 'c1', deleted: { materials: [10] }, memberships: { course_ids: [1], conversation_ids: [] } }),
    } as never)

    await runSync()
    expect(await db.materials.count()).toBe(0) // 10 deleted, 11 belonged to the dropped course
    expect(await db.courses.get(2)).toBeUndefined()
    expect(await db.conversations.get(7)).toBeUndefined()
    expect(await db.messages.count()).toBe(0)
  })

  it('stores synced messages and queues delivered receipts for messages from others', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: page({
        messages: [
          { id: 3, client_uuid: 'x', conversation_id: 7, type: 'text', sender: { id: 2, name: 'B' }, body: 'hi', attachment: null, reply_to: null, deleted: false, status: null, created_at: '2026-10-25T10:00:00Z' },
          { id: 4, client_uuid: 'y', conversation_id: 7, type: 'text', sender: { id: 1, name: 'Me' }, body: 'yo', attachment: null, reply_to: null, deleted: false, status: 'read', created_at: '2026-10-25T10:01:00Z' },
        ],
      }),
    } as never)
    setOnline(false) // keep the receipt in the outbox so we can inspect it
    setOnline(true)
    vi.spyOn(api, 'post').mockRejectedValue(Object.assign(new Error('offline'), { isAxiosError: true }))
    await runSync()
    expect(await db.messages.count()).toBe(2)
    const receipt = await db.outbox.where('type').equals('receipt').first()
    expect(receipt?.payload).toEqual({ delivered: [3], read: [] })
  })

  it('does not run offline', async () => {
    const get = vi.spyOn(api, 'get')
    setOnline(false)
    await runSync()
    expect(get).not.toHaveBeenCalled()
  })
})
