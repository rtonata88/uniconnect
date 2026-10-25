/// <reference lib="webworker" />
import { CacheableResponsePlugin } from 'workbox-cacheable-response'
import { ExpirationPlugin } from 'workbox-expiration'
import { registerRoute } from 'workbox-routing'
import { CacheFirst } from 'workbox-strategies'

declare const self: ServiceWorkerGlobalScope

export const PRIVATE_CACHE = 'uc-private-images'

/**
 * Runtime caching. API data is NOT cached here: it lives in IndexedDB (PRD 9.1).
 * Private images (chat photos, avatars) are cached by path, ignoring the presigned
 * query string that changes on every request, so cached chats show their photos
 * offline (MOB-4). The cache is wiped on logout.
 */
export function registerRuntimeCaching() {
  registerRoute(
    ({ url, request }) => url.origin === self.location.origin && url.pathname.startsWith('/uc-private/') && request.destination === 'image',
    new CacheFirst({
      cacheName: PRIVATE_CACHE,
      plugins: [
        { cacheKeyWillBeUsed: async ({ request }) => new URL(request.url).origin + new URL(request.url).pathname },
        new CacheableResponsePlugin({ statuses: [200] }),
        new ExpirationPlugin({ maxEntries: 300, maxAgeSeconds: 14 * 24 * 3600 }),
      ],
    }),
  )

  self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_PRIVATE_CACHES') event.waitUntil(caches.delete(PRIVATE_CACHE))
  })
}
