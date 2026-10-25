import { useLiveQuery } from 'dexie-react-hooks'
import { CloudDownload, CloudOff, Loader2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { Switch } from '@/components/ui/switch'
import { errorMessage } from '@/lib/api'
import { db } from '@/lib/db'
import { offlineKey, removeOffline, saveOffline } from '@/lib/offlineFiles'
import { useOnline } from '@/lib/useOnline'
import type { Material } from '../types'

/** LMS-3 "Make available offline" (MOB-4). */
export function MaterialOfflineToggle({ material }: { material: Material }) {
  const attachment = material.attachment!
  const saved = useLiveQuery(() => db.files.where('key').equals(offlineKey(attachment.id)).count(), [attachment.id])
  const online = useOnline()
  const [busy, setBusy] = useState(false)
  const toggle = async (on: boolean) => {
    setBusy(true)
    try {
      if (on) {
        await saveOffline(attachment)
        toast.success(`"${material.title}" is available offline`)
      } else {
        await removeOffline(attachment.id)
      }
    } catch (e) {
      toast.error(errorMessage(e, 'Could not save the file'))
    } finally {
      setBusy(false)
    }
  }
  const id = `offline-${material.id}`
  return (
    <label htmlFor={id} className="flex min-h-11 shrink-0 cursor-pointer items-center gap-1.5 px-1 text-xs text-muted-foreground" title="Make available offline">
      {busy ? <Loader2 className="size-4 animate-spin" /> : saved ? <CloudDownload className="size-4 text-emerald-600" /> : <CloudOff className="size-4" />}
      <span className="hidden sm:inline">Offline</span>
      <Switch id={id} checked={!!saved} disabled={busy || (!online && !saved)} onCheckedChange={(v) => void toggle(v)} aria-label={`Make ${material.title} available offline`} />
    </label>
  )
}
