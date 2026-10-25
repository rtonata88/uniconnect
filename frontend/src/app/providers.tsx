import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { UpdatePrompt } from '@/features/pwa/UpdatePrompt'

export const queryClient = new QueryClient({
  defaultOptions: {
    // 'always': queries run offline too — the repository answers from IndexedDB (PRD 9.5)
    queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false, networkMode: 'always' },
    mutations: { networkMode: 'always' },
  },
})

export function Providers({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <TooltipProvider delayDuration={300}>
        {children}
        <Toaster richColors closeButton position="top-center" offset={{ top: 'calc(env(safe-area-inset-top) + 0.75rem)' }} />
        <UpdatePrompt />
      </TooltipProvider>
    </QueryClientProvider>
  )
}
