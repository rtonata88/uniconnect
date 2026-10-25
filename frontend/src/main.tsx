import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { RouterProvider } from 'react-router'
import './index.css'
import '@/app/theme'
import { Providers } from '@/app/providers'
import { router } from '@/app/router'
import '@/features/notifications/setup'
import { useAuth } from '@/features/auth/store'
import { startServiceWorker } from '@/lib/push/sw-register'

void useAuth.getState().init()
startServiceWorker((url) => void router.navigate(url))

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Providers>
      <RouterProvider router={router} />
    </Providers>
  </StrictMode>,
)
