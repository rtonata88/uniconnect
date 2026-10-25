import { create } from 'zustand'
import { api, configureApi } from '@/lib/api'
import { clearLocalData, getMeta, setMeta } from '@/lib/db'
import type { LoginResponse, Me } from './types'

/**
 * Auth state. The token lives in IndexedDB (Dexie `meta`) so the service worker and
 * the app share it (SEC-11), and is mirrored in memory for the axios interceptor.
 */
interface AuthState {
  ready: boolean
  token: string | null
  expiresAt: string | null
  user: Me | null
  init: () => Promise<void>
  login: (email: string, password: string) => Promise<Me>
  setUser: (user: Me) => void
  logout: (opts?: { remote?: boolean; all?: boolean }) => Promise<void>
}

const listeners = new Set<(user: Me | null) => void>()
const beforeLogoutHooks = new Set<() => Promise<void>>()
/** Runs while the token is still valid (e.g. remove this device's push subscription). */
export const beforeLogout = (fn: () => Promise<void>) => {
  beforeLogoutHooks.add(fn)
  return () => beforeLogoutHooks.delete(fn)
}
export const onAuthChange = (fn: (user: Me | null) => void) => {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export const useAuth = create<AuthState>((set, get) => ({
  ready: false,
  token: null,
  expiresAt: null,
  user: null,

  init: async () => {
    const [token, expiresAt, user] = await Promise.all([
      getMeta<string>('token'),
      getMeta<string>('token_expires_at'),
      getMeta<Me>('user'),
    ])
    if (token && expiresAt && new Date(expiresAt).getTime() < Date.now()) {
      // expired: refresh is by re-login (SEC-11)
      await get().logout({ remote: false })
      set({ ready: true })
      return
    }
    set({ token: token ?? null, expiresAt: expiresAt ?? null, user: user ?? null, ready: true })
    listeners.forEach((l) => l(user ?? null))
  },

  login: async (email, password) => {
    const { data } = await api.post<LoginResponse>('/auth/login', {
      email,
      password,
      device_name: navigator.userAgent.slice(0, 100),
    })
    // a different user on this device must not see the previous user's cached data
    const previous = await getMeta<Me>('user')
    if (previous && previous.id !== data.user.id) await clearLocalData()
    await Promise.all([
      setMeta('token', data.token),
      setMeta('token_expires_at', data.expires_at),
      setMeta('user', data.user),
    ])
    set({ token: data.token, expiresAt: data.expires_at, user: data.user })
    listeners.forEach((l) => l(data.user))
    return data.user
  },

  setUser: (user) => {
    void setMeta('user', user)
    set({ user })
  },

  logout: async ({ remote = true, all = false } = {}) => {
    if (remote && get().token) {
      await Promise.allSettled([...beforeLogoutHooks].map((fn) => fn()))
      try {
        await api.post(all ? '/auth/logout-all' : '/auth/logout')
      } catch {
        // offline or token already revoked: still clear locally
      }
    }
    await clearLocalData()
    set({ token: null, expiresAt: null, user: null })
    listeners.forEach((l) => l(null))
  },
}))

configureApi({
  token: () => useAuth.getState().token,
  onUnauthorized: () => {
    if (useAuth.getState().token) void useAuth.getState().logout({ remote: false })
  },
})
