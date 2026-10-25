import { useSyncExternalStore } from 'react'

/**
 * Connectivity as seen by the app. `navigator.onLine` can be wrong (PRD 9.3), so the
 * sync engine (phase 7) overrides it with the result of its /api/health probe via setOnline().
 */
let probe: boolean | null = null
const listeners = new Set<() => void>()

function subscribe(cb: () => void) {
  listeners.add(cb)
  window.addEventListener('online', cb)
  window.addEventListener('offline', cb)
  return () => {
    listeners.delete(cb)
    window.removeEventListener('online', cb)
    window.removeEventListener('offline', cb)
  }
}

export function isOnline() {
  if (!navigator.onLine) return false
  return probe ?? true
}

export function setOnline(value: boolean | null) {
  if (probe === value) return
  probe = value
  listeners.forEach((l) => l())
}

export function useOnline() {
  return useSyncExternalStore(subscribe, isOnline, () => true)
}
