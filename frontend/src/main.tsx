import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import './index.css'
import '@/app/theme'
import { Providers } from '@/app/providers'
import { router } from '@/app/router'
import '@/features/notifications/setup'
import '@/lib/sync/setup'
import { useAuth } from '@/features/auth/store'
import { startServiceWorker } from '@/lib/push/sw-register'

void useAuth.getState().init()

// troubleshooting hook: localStorage.setItem('uc-debug', '1') exposes the sync internals
try {
  if (localStorage.getItem('uc-debug')) {
    void Promise.all([import('@/lib/sync/outbox'), import('@/lib/sync/engine'), import('@/lib/useOnline'), import('@/lib/db')]).then(([outbox, engine, online, db]) => {
      Object.assign(window, { __uc: { outbox, engine, online, db } })
    })
  }
} catch {
  // storage unavailable
}
startServiceWorker((url) => void router.navigate(url))

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Providers>
      <RouterProvider router={router} />
    </Providers>
  </StrictMode>,
)
