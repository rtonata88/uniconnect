import { create } from 'zustand'
import { api } from '@/lib/api'
import { db, getMeta, setMeta } from '@/lib/db'
import { isOnline } from '@/lib/useOnline'
import { applyReceipts, markDeleted, storeServerMessages } from '@/features/chat/data'
import type { Message } from '@/features/chat/types'
import { processOutbox } from './outbox'

/**
 * Delta sync engine (PRD 9.3). Pulls GET /api/sync?since=cursor in a loop while
 * has_more and applies it to IndexedDB, so this device is a partial replica that
 * converges with the server (DIST-7). Runs on start, when the connection returns,
 * after a Reverb reconnect and every 60 s while online.
 */
export interface SyncPayload {
  cursor: string
  has_more: boolean
  courses?: Record<string, unknown>[]
  materials?: Record<string, unknown>[]
  announcements?: Record<string, unknown>[]
  assignments?: Record<string, unknown>[]
  my_submissions?: Record<string, unknown>[]
  grades?: Record<string, unknown>[]
  conversations?: Record<string, unknown>[]
  messages: Message[]
  receipts?: { message_id: number; status: Message['status']; read: number; recipients: number }[]
  notifications?: Record<string, unknown>[]
  deleted: Partial<Record<'courses' | 'materials' | 'announcements' | 'assignments' | 'my_submissions' | 'grades' | 'messages', number[]>>
  memberships?: { course_ids: number[]; conversation_ids: number[] }
}

type Status = 'idle' | 'syncing' | 'error'
export const useSyncStore = create<{ status: Status; lastSyncedAt: number | null; error: string | null }>(() => ({
  status: 'idle',
  lastSyncedAt: null,
  error: null,
}))

const afterSync = new Set<() => void>()
export function onSynced(fn: () => void) {
  afterSync.add(fn)
  return () => afterSync.delete(fn)
}

/* eslint-disable @typescript-eslint/no-explicit-any */
export async function applySync(p: SyncPayload) {
  const tables = [db.courses, db.materials, db.announcements, db.assignments, db.mySubmissions, db.grades, db.conversations, db.notifications, db.messages]
  await db.transaction('rw', tables, async () => {
    if (p.courses?.length) await db.courses.bulkPut(p.courses as any)
    if (p.materials?.length) await db.materials.bulkPut(p.materials as any)
    if (p.announcements?.length) await db.announcements.bulkPut(p.announcements as any)
    if (p.assignments?.length) await db.assignments.bulkPut(p.assignments.map(({ my_submission: _, ...a }) => a) as any)
    if (p.my_submissions?.length) await db.mySubmissions.bulkPut(p.my_submissions as any)
    if (p.grades?.length) await db.grades.bulkPut(p.grades as any)
    if (p.conversations?.length) await db.conversations.bulkPut(p.conversations as any)
    if (p.notifications?.length) await db.notifications.bulkPut(p.notifications as any)

    const d = p.deleted ?? {}
    if (d.courses?.length) await db.courses.bulkDelete(d.courses)
    if (d.materials?.length) await db.materials.bulkDelete(d.materials)
    if (d.announcements?.length) await db.announcements.bulkDelete(d.announcements)
    if (d.assignments?.length) await db.assignments.bulkDelete(d.assignments)
    if (d.my_submissions?.length) await db.mySubmissions.bulkDelete(d.my_submissions)
    if (d.grades?.length) await db.grades.bulkDelete(d.grades)

    // drop what the user is no longer part of (un-enrolled, removed from a group)
    if (p.memberships) {
      const keepCourses = new Set(p.memberships.course_ids)
      const goneCourses = ((await db.courses.toCollection().primaryKeys()) as number[]).filter((id) => !keepCourses.has(id))
      if (goneCourses.length) {
        await db.courses.bulkDelete(goneCourses)
        for (const t of [db.materials, db.announcements, db.assignments, db.grades]) await t.where('course_id').anyOf(goneCourses).delete()
      }
      const keepConvs = new Set(p.memberships.conversation_ids)
      const goneConvs = ((await db.conversations.toCollection().primaryKeys()) as number[]).filter((id) => !keepConvs.has(id))
      if (goneConvs.length) {
        await db.conversations.bulkDelete(goneConvs)
        await db.messages.where('conversation_id').anyOf(goneConvs).delete()
      }
    }
  })
  // messages go through the chat layer so delivered receipts are sent (PRD 9.3: "after sync…")
  await storeServerMessages(p.messages ?? [])
  for (const id of p.deleted?.messages ?? []) await markDeleted(id)
  if (p.receipts?.length) await applyReceipts(p.receipts)
}
/* eslint-enable @typescript-eslint/no-explicit-any */

let running: Promise<void> | null = null

export function runSync(): Promise<void> {
  if (running) return running
  if (!isOnline()) return Promise.resolve()
  const run = (async () => {
    useSyncStore.setState({ status: 'syncing', error: null })
    try {
      // push local changes first, then pull (the server is the source of truth)
      await processOutbox()
      let cursor = await getMeta<string>('sync_cursor')
      let guard = 0
      for (;;) {
        const { data } = await api.get<SyncPayload>('/sync', { params: cursor ? { since: cursor } : {} })
        await applySync(data)
        cursor = data.cursor
        await setMeta('sync_cursor', cursor)
        if (!data.has_more || ++guard > 200) break
      }
      useSyncStore.setState({ status: 'idle', lastSyncedAt: Date.now() })
      afterSync.forEach((fn) => fn())
    } catch (e) {
      useSyncStore.setState({ status: 'error', error: e instanceof Error ? e.message : String(e) })
    }
  })()
  running = run
  void run.finally(() => {
    if (running === run) running = null
  })
  return run
}

let interval: ReturnType<typeof setInterval> | null = null
export function startPeriodicSync() {
  if (interval) return
  interval = setInterval(() => {
    if (isOnline() && document.visibilityState === 'visible') void runSync()
  }, 60_000)
}

export function stopPeriodicSync() {
  if (interval) clearInterval(interval)
  interval = null
}
