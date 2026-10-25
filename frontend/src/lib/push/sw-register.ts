import { registerSW } from 'virtual:pwa-register'
import { create } from 'zustand'

/** Service worker registration + "new version available" state (MOB-2). */
export const useSwStore = create<{ needRefresh: boolean; offlineReady: boolean; update: () => void }>(() => ({
  needRefresh: false,
  offlineReady: false,
  update: () => {},
}))

export function startServiceWorker(navigate: (url: string) => void) {
  if (!('serviceWorker' in navigator)) return
  const updateSW = registerSW({
    immediate: true,
    onNeedRefresh: () => useSwStore.setState({ needRefresh: true }),
    onOfflineReady: () => useSwStore.setState({ offlineReady: true }),
    onRegisteredSW: (_url, reg) => {
      // look for a new version every hour while the app stays open
      if (reg) setInterval(() => void reg.update(), 60 * 60 * 1000)
    },
  })
  useSwStore.setState({ update: () => void updateSW(true) })
  // MOB-7: the worker asks an open window to show the screen of a tapped notification
  navigator.serviceWorker.addEventListener('message', (event) => {
    if (event.data?.type === 'NAVIGATE' && typeof event.data.url === 'string') navigate(event.data.url)
  })
}
