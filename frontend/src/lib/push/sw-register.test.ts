import { describe, expect, it, vi } from 'vitest'

vi.mock('virtual:pwa-register', () => ({ registerSW: () => () => Promise.resolve() }))

describe('MOB-7 notification click', () => {
  it('navigates the open app to the screen named by the service worker', async () => {
    const listeners: ((e: MessageEvent) => void)[] = []
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { addEventListener: (_: string, fn: (e: MessageEvent) => void) => listeners.push(fn) },
    })
    const { startServiceWorker } = await import('./sw-register')
    const navigate = vi.fn()
    startServiceWorker(navigate)

    listeners.forEach((l) => l(new MessageEvent('message', { data: { type: 'NAVIGATE', url: '/chats/12' } })))
    listeners.forEach((l) => l(new MessageEvent('message', { data: { type: 'OTHER', url: '/x' } })))
    expect(navigate).toHaveBeenCalledTimes(1)
    expect(navigate).toHaveBeenCalledWith('/chats/12')
  })
})
