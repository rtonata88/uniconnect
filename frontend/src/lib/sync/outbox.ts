import axios from 'axios'
import { db, type OutboxRow } from '@/lib/db'
import { isOnline } from '@/lib/useOnline'

/**
 * Outbox (PRD 9.2): writes made on this device are queued in IndexedDB and sent
 * in order (FIFO) when online. Files are uploaded first, then the record is sent
 * with the returned attachment_id. Retries back off 1 s, 2 s, 4 s … 60 s; after 5
 * failures the entry is marked failed ("Tap to retry"). 4xx errors other than
 * 408/429 are permanent. The server is idempotent on client_uuid, so a retry after
 * a lost response never creates a duplicate.
 */
export type Handler = (entry: OutboxRow) => Promise<void>

const handlers = new Map<OutboxRow['type'], Handler>()
const listeners = new Set<() => void>()
let running: Promise<void> | null = null
let timer: ReturnType<typeof setTimeout> | null = null

export const MAX_ATTEMPTS = 5

export function registerHandler(type: OutboxRow['type'], handler: Handler) {
  handlers.set(type, handler)
}

export function onOutboxChange(fn: () => void) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export function backoffMs(attempts: number) {
  return Math.min(60_000, 1000 * 2 ** Math.max(0, attempts - 1))
}

/** Permanent = will never succeed by retrying (validation, forbidden, not found…). */
export function isPermanent(error: unknown) {
  if (!axios.isAxiosError(error) || !error.response) return false
  const s = error.response.status
  return s >= 400 && s < 500 && s !== 408 && s !== 429
}

export async function enqueue(entry: Omit<OutboxRow, 'id' | 'attempts' | 'status' | 'created_at'> & { created_at?: number }) {
  await db.outbox.add({ ...entry, created_at: entry.created_at ?? Date.now(), attempts: 0, status: 'pending' })
  listeners.forEach((l) => l())
  void processOutbox()
}

export async function retry(clientUuid: string) {
  await db.outbox.where('client_uuid').equals(clientUuid).modify({ status: 'pending', attempts: 0, next_attempt_at: 0, last_error: undefined })
  listeners.forEach((l) => l())
  void processOutbox()
}

export async function discard(clientUuid: string) {
  await db.outbox.where('client_uuid').equals(clientUuid).delete()
  listeners.forEach((l) => l())
}

function schedule(ms: number) {
  if (timer) clearTimeout(timer)
  timer = setTimeout(() => void processOutbox(), ms)
}

/** Process pending entries in order. Safe to call often; runs one loop at a time. */
export function processOutbox(): Promise<void> {
  if (running) return running
  running = (async () => {
    try {
      while (isOnline()) {
        // FIFO by insertion order; a failed entry is skipped so later ones are not blocked forever
        const entry = await db.outbox.orderBy('id').filter((e) => e.status !== 'failed').first()
        if (!entry) break
        const wait = (entry.next_attempt_at ?? 0) - Date.now()
        if (wait > 0) {
          schedule(wait)
          break
        }
        const handler = handlers.get(entry.type)
        if (!handler) {
          await db.outbox.update(entry.id!, { status: 'failed', last_error: `No handler for ${entry.type}` })
          continue
        }
        await db.outbox.update(entry.id!, { status: 'sending' })
        listeners.forEach((l) => l())
        try {
          await handler(entry)
          await db.outbox.delete(entry.id!)
        } catch (error) {
          const attempts = entry.attempts + 1
          const permanent = isPermanent(error)
          const failed = permanent || attempts >= MAX_ATTEMPTS
          const message = axios.isAxiosError(error)
            ? ((error.response?.data as { message?: string } | undefined)?.message ?? error.message)
            : String(error)
          await db.outbox.update(entry.id!, {
            attempts,
            status: failed ? 'failed' : 'pending',
            last_error: message,
            next_attempt_at: Date.now() + backoffMs(attempts),
          })
          if (failed) {
            await failedHook?.(entry, message)
          } else {
            // retry later; stopping here keeps later entries behind this one (FIFO)
            schedule(backoffMs(attempts))
            break
          }
        }
        listeners.forEach((l) => l())
      }
    } finally {
      running = null
      listeners.forEach((l) => l())
    }
  })()
  return running
}

let failedHook: ((entry: OutboxRow, message: string) => Promise<void>) | null = null
/** Called when an entry is marked failed (e.g. to flag the chat bubble). */
export function onEntryFailed(fn: (entry: OutboxRow, message: string) => Promise<void>) {
  failedHook = fn
}
