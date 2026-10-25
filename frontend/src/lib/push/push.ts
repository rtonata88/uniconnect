import { api } from '@/lib/api'
import { loadConfig } from '@/lib/echo'

/**
 * Web Push (NOT-3). Works in desktop browsers, Android Chrome and iOS/iPadOS 16.4+
 * when the PWA is installed to the home screen.
 */
export function pushSupported() {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

export function isIos() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

export function isStandalone() {
  return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true
}

function urlBase64ToUint8Array(base64: string) {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  return Uint8Array.from(raw, (c) => c.charCodeAt(0))
}

export async function currentSubscription(): Promise<PushSubscription | null> {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.getRegistration()
  return (await reg?.pushManager.getSubscription()) ?? null
}

/** Ask permission (must be called from a user gesture) and register this device. */
export async function enablePush(): Promise<PushSubscription> {
  if (!pushSupported()) throw new Error(isIos() && !isStandalone() ? 'On iPhone and iPad, install the app to your home screen first, then enable notifications.' : 'This browser does not support push notifications.')
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') throw new Error('Notifications are blocked. Allow them in your browser settings.')
  const reg = await navigator.serviceWorker.ready
  const { vapid_public_key } = await loadConfig()
  if (!vapid_public_key) throw new Error('Push is not configured on the server.')
  const sub =
    (await reg.pushManager.getSubscription()) ??
    (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapid_public_key) }))
  await api.post('/push/webpush/subscribe', { ...sub.toJSON(), contentEncoding: (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.[0] ?? 'aes128gcm' })
  return sub
}

export async function disablePush() {
  const sub = await currentSubscription()
  if (!sub) return
  try {
    await api.delete('/push/webpush/subscribe', { data: { endpoint: sub.endpoint } })
  } finally {
    await sub.unsubscribe()
  }
}

/** Re-send an existing subscription after login (endpoint may belong to another account). */
export async function refreshPushRegistration() {
  const sub = await currentSubscription()
  if (sub && Notification.permission === 'granted') {
    await api.post('/push/webpush/subscribe', { ...sub.toJSON(), contentEncoding: 'aes128gcm' }).catch(() => undefined)
  }
}
