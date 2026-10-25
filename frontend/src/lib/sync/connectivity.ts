import axios from 'axios'
import { api } from '@/lib/api'
import { isOnline, setOnline } from '@/lib/useOnline'

/**
 * Connectivity monitor (PRD 9.3). `navigator.onLine` can be wrong (captive portals,
 * a dead Wi-Fi), so the app also pings /api/health: every 10 s while offline, and
 * right away on the browser's online/offline events. Any request that fails without
 * a response flips the app to offline at once.
 */
const listeners = new Set<() => void>()
let timer: ReturnType<typeof setInterval> | null = null
let started = false

export function onBackOnline(fn: () => void) {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

export async function probe(): Promise<boolean> {
  if (!navigator.onLine) {
    setOnline(false)
    return false
  }
  try {
    await api.get('/health', { timeout: 4000, headers: { 'Cache-Control': 'no-store' } })
    markOnline()
    return true
  } catch (e) {
    // a 503 still means the gateway is reachable; only a missing response is "offline"
    if (axios.isAxiosError(e) && e.response) {
      markOnline()
      return true
    }
    setOnline(false)
    return false
  }
}

function markOnline() {
  const was = isOnline()
  setOnline(true)
  if (!was) listeners.forEach((l) => l())
}

export function startConnectivity() {
  if (started) return
  started = true
  window.addEventListener('online', () => void probe())
  window.addEventListener('offline', () => setOnline(false))
  api.interceptors.response.use(undefined, (error) => {
    if (axios.isAxiosError(error) && !error.response && error.code !== 'ERR_CANCELED') setOnline(false)
    return Promise.reject(error)
  })
  timer = setInterval(() => {
    if (!isOnline()) void probe()
  }, 10_000)
  void probe()
}

export function stopConnectivity() {
  if (timer) clearInterval(timer)
  timer = null
}
