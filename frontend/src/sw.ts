/// <reference lib="webworker" />
import { cleanupOutdatedCaches, createHandlerBoundToURL, precacheAndRoute } from 'workbox-precaching'
import { NavigationRoute, registerRoute } from 'workbox-routing'
import { registerRuntimeCaching } from './sw-runtime'

declare const self: ServiceWorkerGlobalScope

/*
 * UniConnect service worker.
 * - App shell precache so the app opens with no network (MOB-2)
 * - Web Push display and notification click handling (NOT-3, MOB-7)
 */

precacheAndRoute(self.__WB_MANIFEST)
cleanupOutdatedCaches()

// SPA: every navigation gets index.html, except server routes behind the gateway
registerRoute(
  new NavigationRoute(createHandlerBoundToURL('/index.html'), {
    denylist: [/^\/api\//, /^\/app\//, /^\/apps\//, /^\/horizon/, /^\/uc-private\//, /^\/vendor\//],
  }),
)

registerRuntimeCaching()

// "Update available" prompt: the page asks the waiting worker to take over (MOB-2)
self.addEventListener('message', (event) => {
  if (event.data?.type === 'SKIP_WAITING') void self.skipWaiting()
})

interface PushPayload {
  title: string
  body?: string
  icon?: string
  badge?: string
  tag?: string
  data?: { url?: string; category?: string }
}

self.addEventListener('push', (event) => {
  let payload: PushPayload
  try {
    payload = event.data?.json() as PushPayload
  } catch {
    payload = { title: 'UniConnect', body: event.data?.text() }
  }
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      // the app is open and focused: the in-app toast already told the user (NOT-3)
      if (windows.some((w) => w.focused && w.visibilityState === 'visible')) return
      await self.registration.showNotification(payload.title, {
        body: payload.body,
        icon: payload.icon ?? '/pwa-192x192.png',
        badge: payload.badge ?? '/pwa-64x64.png',
        tag: payload.tag,
        data: payload.data,
      })
    })(),
  )
})

// MOB-7: tapping a notification opens or focuses the app on the right screen
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL((event.notification.data as { url?: string } | undefined)?.url ?? '/', self.location.origin).href
  event.waitUntil(
    (async () => {
      const windows = (await self.clients.matchAll({ type: 'window', includeUncontrolled: true })) as WindowClient[]
      const client = windows.find((w) => new URL(w.url).origin === self.location.origin)
      if (client) {
        await client.focus()
        client.postMessage({ type: 'NAVIGATE', url: new URL(target).pathname + new URL(target).search })
        return
      }
      await self.clients.openWindow(target)
    })(),
  )
})
