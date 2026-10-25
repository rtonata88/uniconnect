import { RefreshCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useSwStore } from '@/lib/push/sw-register'

/** MOB-2: a new version was downloaded in the background — offer to reload. */
export function UpdatePrompt() {
  const { needRefresh, update } = useSwStore()
  if (!needRefresh) return null
  return (
    <div
      role="alertdialog"
      aria-label="Update available"
      className="fixed inset-x-3 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-50 mx-auto flex max-w-md items-center gap-3 rounded-xl border bg-popover p-3 shadow-lg md:bottom-4"
    >
      <RefreshCw className="size-5 shrink-0 text-primary" />
      <p className="flex-1 text-sm">A new version of UniConnect is available.</p>
      <Button size="sm" variant="ghost" onClick={() => useSwStore.setState({ needRefresh: false })}>
        Later
      </Button>
      <Button size="sm" onClick={update}>
        Reload
      </Button>
    </div>
  )
}
