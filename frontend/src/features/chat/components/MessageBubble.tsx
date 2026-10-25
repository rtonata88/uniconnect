import { useLiveQuery } from 'dexie-react-hooks'
import { Ban, CornerUpLeft, Info, MoreVertical, RotateCcw, Trash2 } from 'lucide-react'
import { memo, useEffect, useState } from 'react'
import { FileCard } from '@/components/FileCard'
import { SecureImage } from '@/components/SecureImage'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Progress } from '@/components/ui/progress'
import { db } from '@/lib/db'
import { formatBytes } from '@/lib/files'
import { fmtTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { tickOf, type Message } from '../types'
import { Ticks } from './Ticks'

const DELETE_WINDOW_MS = 60 * 60 * 1000

/** Preview of a not-yet-uploaded image straight from IndexedDB. */
function LocalImage({ fileKey, alt, onOpen }: { fileKey: string; alt: string; onOpen: (src: string) => void }) {
  const file = useLiveQuery(() => db.files.where('key').equals(fileKey).first(), [fileKey])
  const [src, setSrc] = useState<string>()
  useEffect(() => {
    if (!file) return
    const url = URL.createObjectURL(file.blob)
    setSrc(url)
    return () => URL.revokeObjectURL(url)
  }, [file])
  return src ? <img src={src} alt={alt} className="max-h-72 rounded-md object-cover" onClick={() => onOpen(src)} /> : null
}

export interface BubbleActions {
  onReply: (m: Message) => void
  onDelete: (m: Message) => void
  onInfo: (m: Message) => void
  onRetry: (m: Message) => void
  onDiscard: (m: Message) => void
  onOpenImage: (img: { id?: number; src?: string; name: string }) => void
}

export const MessageBubble = memo(function MessageBubble({
  message: m,
  mine,
  showSender,
  actions,
}: {
  message: Message
  mine: boolean
  showSender: boolean
  actions: BubbleActions
}) {
  if (m.type === 'system') {
    return (
      <div className="my-2 flex justify-center">
        <span className="rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">{m.body}</span>
      </div>
    )
  }
  const status = tickOf(m)
  const canDelete = mine && !!m.id && !m.deleted && Date.now() - new Date(m.created_at).getTime() < DELETE_WINDOW_MS

  return (
    <div className={cn('group flex px-2 py-0.5', mine ? 'justify-end' : 'justify-start')} data-testid="message" data-status={status}>
      <div
        className={cn(
          'relative max-w-[85%] rounded-2xl px-3 py-1.5 text-sm shadow-xs sm:max-w-[70%]',
          mine ? 'rounded-br-sm bg-emerald-100 text-emerald-950 dark:bg-emerald-900 dark:text-emerald-50' : 'rounded-bl-sm bg-card',
        )}
      >
        {showSender && !mine && m.sender && <p className="mb-0.5 text-xs font-semibold text-primary">{m.sender.name}</p>}
        {m.deleted ? (
          <p className="flex items-center gap-1.5 text-muted-foreground italic">
            <Ban className="size-3.5" /> This message was deleted
          </p>
        ) : (
          <>
            {m.reply_to && (
              <div className="mb-1 rounded-md border-l-4 border-primary/60 bg-black/5 px-2 py-1 text-xs dark:bg-white/10">
                <p className="font-semibold">{m.reply_to.sender_name}</p>
                <p className="line-clamp-2 text-muted-foreground">{m.reply_to.excerpt ?? 'Deleted message'}</p>
              </div>
            )}
            {m.type === 'image' && (
              <div className="-mx-1 mb-1">
                {m.attachment ? (
                  <SecureImage
                    attachmentId={m.attachment.id}
                    alt={m.attachment.name}
                    className="max-h-72 rounded-md object-cover"
                    onClick={() => actions.onOpenImage({ id: m.attachment!.id, name: m.attachment!.name })}
                  />
                ) : (
                  m.local_file_key && <LocalImage fileKey={m.local_file_key} alt={m.local_file?.name ?? ''} onOpen={(src) => actions.onOpenImage({ src, name: m.local_file?.name ?? '' })} />
                )}
              </div>
            )}
            {m.type === 'file' &&
              (m.attachment ? (
                <FileCard attachment={m.attachment} className="mb-1 bg-background/60" />
              ) : (
                m.local_file && (
                  <div className="mb-1 rounded-md bg-background/60 p-2 text-xs">
                    <p className="font-medium">{m.local_file.name}</p>
                    <p className="text-muted-foreground">{formatBytes(m.local_file.size)}</p>
                  </div>
                )
              ))}
            {m.local_status === 'pending' && m.upload_progress !== undefined && m.upload_progress < 100 && (
              <Progress value={m.upload_progress} className="mb-1 h-1" aria-label="Upload progress" />
            )}
            {m.body && <p className="break-words whitespace-pre-wrap">{m.body}</p>}
          </>
        )}
        <div className="mt-0.5 flex items-center justify-end gap-1 text-[11px] text-muted-foreground">
          <time dateTime={m.created_at}>{fmtTime(m.created_at)}</time>
          {mine && !m.deleted && <Ticks status={status} />}
        </div>
        {m.local_status === 'failed' && (
          <div className="mt-1 flex items-center gap-2 text-xs text-destructive">
            <span className="flex-1">{m.local_error ?? 'Not sent'}</span>
            <button type="button" className="inline-flex min-h-8 items-center gap-1 font-medium underline" onClick={() => actions.onRetry(m)}>
              <RotateCcw className="size-3" /> Tap to retry
            </button>
            <button type="button" className="min-h-8 underline" onClick={() => actions.onDiscard(m)}>
              Discard
            </button>
          </div>
        )}
      </div>
      {!m.deleted && m.id && (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              className={cn('size-8 self-center opacity-60 group-hover:opacity-100 focus-visible:opacity-100 md:opacity-0', mine ? 'order-first' : '')}
              aria-label="Message actions"
            >
              <MoreVertical className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align={mine ? 'end' : 'start'}>
            <DropdownMenuItem onClick={() => actions.onReply(m)}>
              <CornerUpLeft /> Reply
            </DropdownMenuItem>
            {mine && (
              <DropdownMenuItem onClick={() => actions.onInfo(m)}>
                <Info /> Message info
              </DropdownMenuItem>
            )}
            {canDelete && (
              <DropdownMenuItem variant="destructive" onClick={() => actions.onDelete(m)}>
                <Trash2 /> Delete for everyone
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      )}
    </div>
  )
})

export function DateSeparator({ date }: { date: string }) {
  const d = new Date(date)
  const today = new Date()
  const yesterday = new Date(Date.now() - 864e5)
  const label =
    d.toDateString() === today.toDateString()
      ? 'Today'
      : d.toDateString() === yesterday.toDateString()
        ? 'Yesterday'
        : d.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' })
  return (
    <div className="my-2 flex justify-center">
      <span className="rounded-md bg-muted/90 px-2 py-0.5 text-xs text-muted-foreground shadow-xs backdrop-blur">{label}</span>
    </div>
  )
}
