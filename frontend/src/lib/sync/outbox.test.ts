import { AxiosError, AxiosHeaders } from 'axios'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { db } from '@/lib/db'
import { setOnline } from '@/lib/useOnline'
import { backoffMs, enqueue, isPermanent, MAX_ATTEMPTS, processOutbox, registerHandler } from './outbox'

const httpError = (status: number) =>
  new AxiosError('fail', 'ERR', undefined, undefined, { status, data: { message: `HTTP ${status}` }, statusText: '', headers: {}, config: { headers: new AxiosHeaders() } })
const networkError = () => new AxiosError('Network Error', 'ERR_NETWORK')

describe('outbox (PRD 9.2)', () => {
  beforeEach(async () => {
    await db.delete()
    await db.open()
    setOnline(true)
  })
  afterEach(() => vi.useRealTimers())

  it('processes entries in FIFO order', async () => {
    const seen: string[] = []
    registerHandler('message', async (e) => void seen.push(e.client_uuid))
    for (const id of ['a', 'b', 'c', 'd']) await enqueue({ client_uuid: id, type: 'message', payload: {} })
    await processOutbox()
    expect(seen).toEqual(['a', 'b', 'c', 'd'])
    expect(await db.outbox.count()).toBe(0)
  })

  it('stops on a network error and keeps later entries behind (order preserved)', async () => {
    const seen: string[] = []
    let fail = true
    registerHandler('message', async (e) => {
      if (e.client_uuid === 'a' && fail) throw networkError()
      seen.push(e.client_uuid)
    })
    await enqueue({ client_uuid: 'a', type: 'message', payload: {} })
    await enqueue({ client_uuid: 'b', type: 'message', payload: {} })
    await processOutbox()
    expect(seen).toEqual([])
    const a = await db.outbox.where('client_uuid').equals('a').first()
    expect(a?.attempts).toBe(1)
    expect(a?.status).toBe('pending')

    fail = false
    await db.outbox.update(a!.id!, { next_attempt_at: 0 })
    await processOutbox()
    expect(seen).toEqual(['a', 'b'])
  })

  it('backs off exponentially 1 s, 2 s, 4 s … up to 60 s', () => {
    expect([1, 2, 3, 4, 5, 6, 7, 8].map(backoffMs)).toEqual([1000, 2000, 4000, 8000, 16000, 32000, 60000, 60000])
  })

  it(`marks an entry failed after ${MAX_ATTEMPTS} attempts`, async () => {
    registerHandler('message', async () => {
      throw httpError(503)
    })
    await enqueue({ client_uuid: 'x', type: 'message', payload: {} })
    for (let i = 0; i < MAX_ATTEMPTS; i++) {
      await db.outbox.toCollection().modify({ next_attempt_at: 0 })
      await processOutbox()
    }
    const x = await db.outbox.where('client_uuid').equals('x').first()
    expect(x?.status).toBe('failed')
    expect(x?.attempts).toBe(MAX_ATTEMPTS)
    expect(x?.last_error).toBe('HTTP 503')
  })

  it('treats 4xx (except 408/429) as permanent and does not block later entries', async () => {
    expect(isPermanent(httpError(422))).toBe(true)
    expect(isPermanent(httpError(403))).toBe(true)
    expect(isPermanent(httpError(408))).toBe(false)
    expect(isPermanent(httpError(429))).toBe(false)
    expect(isPermanent(httpError(500))).toBe(false)
    expect(isPermanent(networkError())).toBe(false)

    const seen: string[] = []
    registerHandler('message', async (e) => {
      if (e.client_uuid === 'bad') throw httpError(422)
      seen.push(e.client_uuid)
    })
    await enqueue({ client_uuid: 'bad', type: 'message', payload: {} })
    await enqueue({ client_uuid: 'good', type: 'message', payload: {} })
    await processOutbox()
    expect(seen).toEqual(['good'])
    expect((await db.outbox.where('client_uuid').equals('bad').first())?.status).toBe('failed')
  })

  it('a call made while offline does not block processing once back online (regression)', async () => {
    const seen: string[] = []
    registerHandler('message', async (e) => void seen.push(e.client_uuid))
    setOnline(false)
    await enqueue({ client_uuid: 'later', type: 'message', payload: {} })
    await processOutbox()
    setOnline(true)
    await processOutbox()
    expect(seen).toEqual(['later'])
  })

  it('does nothing while offline', async () => {
    const handler = vi.fn()
    registerHandler('message', handler)
    setOnline(false)
    await enqueue({ client_uuid: 'o', type: 'message', payload: {} })
    await processOutbox()
    expect(handler).not.toHaveBeenCalled()
    expect(await db.outbox.count()).toBe(1)
  })
})
