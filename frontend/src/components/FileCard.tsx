import { Download, FileImage, FileSpreadsheet, FileText, File as FileIcon, Loader2, Presentation } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import type { ReactNode } from 'react'
import type { Attachment } from '@/features/courses/types'
import { errorMessage } from '@/lib/api'
import { fileKind, formatBytes, openAttachment } from '@/lib/files'
import { cn } from '@/lib/utils'

const icons = { pdf: FileText, image: FileImage, doc: FileText, slides: Presentation, sheet: FileSpreadsheet, file: FileIcon }

/** Download card with name and size (MSG-6, LMS-3). Opens PDFs/images inline, downloads others. */
export function FileCard({
  attachment,
  className,
  extra,
  onOpen,
}: {
  attachment: Pick<Attachment, 'id' | 'name' | 'mime' | 'size'>
  className?: string
  extra?: ReactNode
  /** override (e.g. open an offline copy) */
  onOpen?: () => Promise<void> | void
}) {
  const [busy, setBusy] = useState(false)
  const Icon = icons[fileKind(attachment.mime)]
  const open = async () => {
    setBusy(true)
    try {
      await (onOpen ? onOpen() : openAttachment(attachment))
    } catch (e) {
      toast.error(errorMessage(e, 'Could not open the file'))
    } finally {
      setBusy(false)
    }
  }
  return (
    <div className={cn('flex min-h-14 items-center gap-3 rounded-lg border bg-card p-2 pr-1', className)}>
      <div className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted">
        <Icon className="size-5 text-muted-foreground" />
      </div>
      <button type="button" onClick={open} className="min-w-0 flex-1 text-left" disabled={busy}>
        <p className="truncate text-sm font-medium">{attachment.name}</p>
        <p className="text-xs text-muted-foreground">
          {formatBytes(attachment.size)} · {fileKind(attachment.mime).toUpperCase()}
        </p>
      </button>
      {extra}
      <button
        type="button"
        onClick={open}
        disabled={busy}
        aria-label={`Open ${attachment.name}`}
        className="flex size-11 shrink-0 items-center justify-center rounded-md hover:bg-muted"
      >
        {busy ? <Loader2 className="size-4 animate-spin" /> : <Download className="size-4" />}
      </button>
    </div>
  )
}
