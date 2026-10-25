import { useLiveQuery } from 'dexie-react-hooks'
import { CloudOff, Loader2, RefreshCw } from 'lucide-react'
import { db } from '@/lib/db'
import { useOnline } from '@/lib/useOnline'
import { cn } from '@/lib/utils'
import { runSync, useSyncStore } from './engine'

/** MOB-6: "Online" · "Offline – changes will sync" · "Syncing…" */
export function SyncStatusPill() {
  const online = useOnline()
  const status = useSyncStore((s) => s.status)
  const pending = useLiveQuery(() => db.outbox.filter((e) => e.status !== 'failed' && e.type !== 'receipt').count(), []) ?? 0
  const failed = useLiveQuery(() => db.outbox.where('status').equals('failed').count(), []) ?? 0
  const syncing = online && (status === 'syncing' || pending > 0)

  const label = !online ? 'Offline – changes will sync' : syncing ? 'Syncing…' : failed ? `${failed} not sent` : 'Online'
  return (
    <button
      type="button"
      onClick={() => online && void runSync()}
      className={cn(
        'inline-flex h-8 max-w-[11rem] items-center gap-1.5 truncate rounded-full border px-2.5 text-xs font-medium sm:max-w-none',
        !online ? 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400' : failed && !syncing ? 'border-destructive/40 text-destructive' : 'text-muted-foreground',
      )}
      aria-live="polite"
      title={online ? 'Sync now' : 'Your changes are saved on this device and will be sent when you are back online'}
    >
      {!online ? <CloudOff className="size-3.5 shrink-0" /> : syncing ? <Loader2 className="size-3.5 shrink-0 animate-spin" /> : failed ? <RefreshCw className="size-3.5 shrink-0" /> : <span className="size-2 shrink-0 rounded-full bg-emerald-500" />}
      <span className="truncate">
        {label}
        {!online && pending > 0 && ` (${pending})`}
      </span>
    </button>
  )
}

/** MOB-4 / PRD 9.5: shown on pages while they display the local copy. */
export function OfflineBanner() {
  const online = useOnline()
  if (online) return null
  return (
    <div role="status" className="flex items-center justify-center gap-2 bg-amber-500/15 px-3 py-1.5 text-xs text-amber-800 dark:text-amber-300">
      <CloudOff className="size-3.5" /> Offline – showing saved data
    </div>
  )
}
