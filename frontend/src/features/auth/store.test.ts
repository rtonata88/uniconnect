import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '@/lib/api'
import { db, getMeta, setMeta } from '@/lib/db'
import { useAuth } from './store'

const user = { id: 7, name: 'Anna', email: 'a@u.test', role: 'student', notification_preferences: {} }

describe('auth store (SEC-11)', () => {
  beforeEach(async () => {
    vi.restoreAllMocks()
    await db.delete()
    await db.open()
    useAuth.setState({ token: null, user: null, ready: false, expiresAt: null })
  })

  it('stores the token in IndexedDB meta on login and sends it on requests', async () => {
    vi.spyOn(api, 'post').mockResolvedValueOnce({ data: { token: 'tok', expires_at: '2099-01-01T00:00:00Z', user } })
    await useAuth.getState().login('a@u.test', 'pw')

    expect(await getMeta('token')).toBe('tok')
    expect(useAuth.getState().user?.id).toBe(7)

    const handler = (api.interceptors.request as unknown as { handlers: { fulfilled: (c: object) => { headers: Record<string, string> } }[] }).handlers[0]
    const config = handler.fulfilled({ headers: {} })
    expect(config.headers.Authorization).toBe('Bearer tok')
    expect(config.headers['X-Request-Id']).toMatch(/[0-9a-f-]{36}/)
  })

  it('restores the session on start and drops an expired token', async () => {
    await setMeta('token', 'old')
    await setMeta('token_expires_at', '2000-01-01T00:00:00Z')
    await setMeta('user', user)
    await useAuth.getState().init()
    expect(useAuth.getState().token).toBeNull()
    expect(await getMeta('token')).toBeUndefined()
  })

  it('clears the token and local data on logout', async () => {
    await setMeta('token', 'tok')
    await db.courses.put({ id: 1, code: 'X' })
    useAuth.setState({ token: 'tok', user: user as never })
    vi.spyOn(api, 'post').mockResolvedValueOnce({ data: {} })

    await useAuth.getState().logout()
    expect(await getMeta('token')).toBeUndefined()
    expect(await db.courses.count()).toBe(0)
  })
})
