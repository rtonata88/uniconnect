import { Camera, CornerUpLeft, Paperclip, Send, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { useAuth } from '@/features/auth/store'
import { MAX_UPLOAD_BYTES } from '@/lib/files'
import { sendMessage } from '../data'
import { whisperTyping } from '../realtime'
import type { Conversation, Message } from '../types'

const ACCEPT_FILES = '.pdf,.doc,.docx,.ppt,.pptx,.xlsx,.txt,.csv,.zip,image/*'

export function Composer({
  conversation,
  replyTo,
  onCancelReply,
  onSent,
}: {
  conversation: Conversation
  replyTo: Message | null
  onCancelReply: () => void
  onSent: () => void
}) {
  const user = useAuth((s) => s.user)!
  const [text, setText] = useState('')
  const area = useRef<HTMLTextAreaElement>(null)
  const typingSent = useRef(0)
  const idleTimer = useRef<ReturnType<typeof setTimeout>>(undefined)

  // grow with content up to ~6 lines
  useEffect(() => {
    const el = area.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`
  }, [text])

  useEffect(() => {
    if (replyTo) area.current?.focus()
  }, [replyTo])

  const stopTyping = () => {
    if (typingSent.current) {
      whisperTyping(conversation.id, user, false)
      typingSent.current = 0
    }
  }

  // MSG-8: whisper at most every 2.5 s while typing; "stopped" after 3 s idle
  const onTyping = () => {
    if (Date.now() - typingSent.current > 2500) {
      whisperTyping(conversation.id, user, true)
      typingSent.current = Date.now()
    }
    clearTimeout(idleTimer.current)
    idleTimer.current = setTimeout(stopTyping, 3000)
  }

  const send = async () => {
    const body = text.trim()
    if (!body) return
    setText('')
    stopTyping()
    await sendMessage({ conversation, body, replyTo })
    onCancelReply()
    onSent()
  }

  const sendFile = async (file: File | undefined) => {
    if (!file) return
    if (file.size > MAX_UPLOAD_BYTES) {
      toast.error('Files may be at most 20 MB.')
      return
    }
    await sendMessage({ conversation, file, body: text.trim() || undefined, replyTo })
    setText('')
    onCancelReply()
    onSent()
  }

  if (!conversation.can_post) {
    return (
      <div className="border-t bg-muted/40 p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-center text-sm text-muted-foreground">
        Only lecturers can send messages in this chat.
      </div>
    )
  }

  return (
    <div className="border-t bg-background p-2 pb-[max(0.5rem,env(safe-area-inset-bottom))]">
      {replyTo && (
        <div className="mb-2 flex items-center gap-2 rounded-md border-l-4 border-primary bg-muted/60 px-2 py-1.5 text-xs">
          <CornerUpLeft className="size-4 shrink-0 text-muted-foreground" />
          <div className="min-w-0 flex-1">
            <p className="font-semibold">Replying to {replyTo.sender?.name ?? 'message'}</p>
            <p className="truncate text-muted-foreground">{replyTo.body ?? replyTo.attachment?.name}</p>
          </div>
          <Button variant="ghost" size="icon" className="size-8" aria-label="Cancel reply" onClick={onCancelReply}>
            <X />
          </Button>
        </div>
      )}
      <div className="flex items-end gap-1">
        <Button variant="ghost" size="icon" className="size-11 shrink-0" asChild>
          <label aria-label="Attach a file" className="cursor-pointer">
            <Paperclip />
            <input type="file" accept={ACCEPT_FILES} className="sr-only" onChange={(e) => (void sendFile(e.target.files?.[0]), (e.target.value = ''))} />
          </label>
        </Button>
        <Button variant="ghost" size="icon" className="size-11 shrink-0" asChild>
          <label aria-label="Take a photo" className="cursor-pointer">
            <Camera />
            <input type="file" accept="image/*" capture="environment" className="sr-only" onChange={(e) => (void sendFile(e.target.files?.[0]), (e.target.value = ''))} />
          </label>
        </Button>
        <label htmlFor="composer" className="sr-only">
          Message
        </label>
        <textarea
          id="composer"
          ref={area}
          rows={1}
          value={text}
          placeholder="Message"
          maxLength={10000}
          className="min-h-11 flex-1 resize-none rounded-2xl border bg-background px-4 py-2.5 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
          onChange={(e) => {
            setText(e.target.value)
            onTyping()
          }}
          onKeyDown={(e) => {
            // Enter sends, Shift+Enter adds a new line (PRD 10.2)
            if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault()
              void send()
            }
          }}
          onBlur={stopTyping}
        />
        <Button size="icon" className="size-11 shrink-0 rounded-full" aria-label="Send" disabled={!text.trim()} onClick={() => void send()}>
          <Send />
        </Button>
      </div>
    </div>
  )
}
