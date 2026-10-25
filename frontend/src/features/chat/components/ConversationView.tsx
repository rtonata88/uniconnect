import { useMutation, useQuery } from '@tanstack/react-query'
import { useLiveQuery } from 'dexie-react-hooks'
import { ArrowDown, BellOff, ChevronLeft, Info, Loader2, Megaphone } from 'lucide-react'
import { Fragment, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { UserAvatar } from '@/components/common'
import { Button } from '@/components/ui/button'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { useAuth } from '@/features/auth/store'
import { api, errorMessage } from '@/lib/api'
import { db } from '@/lib/db'
import { fmtDateTime, fromNow } from '@/lib/format'
import { useOnline } from '@/lib/useOnline'
import { deleteForEveryone, discardMessage, loadMessages, markRead, retryMessage } from '../data'
import { typingLabel, useChatStore } from '../store'
import type { Conversation, Message } from '../types'
import { Composer } from './Composer'
import { GroupInfoSheet } from './GroupInfoSheet'
import { Lightbox } from './Lightbox'
import { DateSeparator, MessageBubble, type BubbleActions } from './MessageBubble'
import { Ticks } from './Ticks'

/** Server messages in id order, then pending ones in creation order (PRD §6). */
export function orderMessages(rows: Message[]): Message[] {
  const sent = rows.filter((m) => m.id).sort((a, b) => a.id! - b.id!)
  const pending = rows.filter((m) => !m.id).sort((a, b) => a.created_at.localeCompare(b.created_at))
  return [...sent, ...pending]
}

function MessageInfoSheet({ message, onClose }: { message: Message | null; onClose: () => void }) {
  const info = useQuery({
    queryKey: ['message-info', message?.id],
    enabled: !!message?.id,
    queryFn: async () =>
      (
        await api.get<{ status: Message['status']; delivered: number; read: number; recipients: { user: { id: number; name: string }; delivered_at: string | null; read_at: string | null }[] }>(
          `/messages/${message!.id}/info`,
        )
      ).data,
  })
  return (
    <Sheet open={!!message} onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="overflow-y-auto">
        <SheetHeader>
          <SheetTitle>Message info</SheetTitle>
          <SheetDescription className="line-clamp-3">{message?.body ?? message?.attachment?.name}</SheetDescription>
        </SheetHeader>
        <div className="space-y-4 px-4 pb-6">
          {info.data && (
            <>
              <p className="flex items-center gap-2 text-sm">
                <Ticks status={info.data.status ?? 'sent'} /> Read by {info.data.read} of {info.data.recipients.length} · delivered to {info.data.delivered}
              </p>
              <ul className="divide-y">
                {info.data.recipients.map((r) => (
                  <li key={r.user.id} className="flex min-h-12 items-center gap-3 py-2">
                    <UserAvatar user={r.user} className="size-8" />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">{r.user.name}</p>
                      <p className="text-xs text-muted-foreground">
                        {r.read_at ? `Read ${fmtDateTime(r.read_at)}` : r.delivered_at ? `Delivered ${fmtDateTime(r.delivered_at)}` : 'Not delivered yet'}
                      </p>
                    </div>
                    <Ticks status={r.read_at ? 'read' : r.delivered_at ? 'delivered' : 'sent'} />
                  </li>
                ))}
              </ul>
            </>
          )}
          {info.isPending && <Loader2 className="animate-spin" />}
        </div>
      </SheetContent>
    </Sheet>
  )
}

function Header({ conversation, onInfo }: { conversation: Conversation; onInfo: () => void }) {
  const online = useChatStore((s) => (conversation.other_user ? s.online.has(conversation.other_user.id) : false))
  const typists = useChatStore((s) => s.typing[conversation.id])
  const [, tick] = useState(0)
  useEffect(() => {
    // re-render so expired typing indicators disappear
    const t = setInterval(() => tick((n) => n + 1), 1000)
    return () => clearInterval(t)
  }, [])
  const typing = typingLabel(typists)
  const muted = conversation.muted_until && new Date(conversation.muted_until) > new Date()
  const subtitle =
    typing ??
    (conversation.type === 'direct'
      ? online
        ? 'online'
        : conversation.other_user?.last_seen_at
          ? `last seen ${fromNow(conversation.other_user.last_seen_at)}`
          : 'offline'
      : `${conversation.participant_count} members`)

  return (
    <div className="flex h-14 shrink-0 items-center gap-2 border-b px-2">
      <Button variant="ghost" size="icon" className="size-11 md:hidden" asChild>
        <Link to="/chats" aria-label="Back to chats">
          <ChevronLeft />
        </Link>
      </Button>
      <button type="button" className="flex min-w-0 flex-1 items-center gap-3 text-left" onClick={onInfo}>
        <UserAvatar user={{ name: conversation.title, avatar_url: conversation.avatar_url }} className="size-10" online={conversation.type === 'direct' ? online : undefined} />
        <div className="min-w-0">
          <p className="flex items-center gap-1 truncate font-medium">
            {conversation.title}
            {conversation.announcements_only && <Megaphone className="size-3.5 text-muted-foreground" aria-label="announcements only" />}
            {muted && <BellOff className="size-3.5 text-muted-foreground" aria-label="muted" />}
          </p>
          <p className={typing ? 'truncate text-xs text-primary' : 'truncate text-xs text-muted-foreground'} aria-live="polite">
            {subtitle}
          </p>
        </div>
      </button>
      <Button variant="ghost" size="icon" className="size-11" aria-label="Chat info" onClick={onInfo}>
        <Info />
      </Button>
    </div>
  )
}

export function ConversationView({ conversation }: { conversation: Conversation }) {
  const me = useAuth((s) => s.user)!
  const online = useOnline()
  const scroller = useRef<HTMLDivElement>(null)
  const [replyTo, setReplyTo] = useState<Message | null>(null)
  const [infoFor, setInfoFor] = useState<Message | null>(null)
  const [groupInfo, setGroupInfo] = useState(false)
  const [image, setImage] = useState<{ id?: number; src?: string; name: string } | null>(null)
  const [paging, setPaging] = useState<{ hasMore: boolean; nextBefore: number | null; loading: boolean }>({ hasMore: true, nextBefore: null, loading: false })
  const [atBottom, setAtBottom] = useState(true)
  const prevHeight = useRef<number | null>(null)

  const rows = useLiveQuery(() => db.messages.where('conversation_id').equals(conversation.id).toArray(), [conversation.id]) as Message[] | undefined
  const messages = useMemo(() => orderMessages(rows ?? []), [rows])

  // first page from the server (the local copy shows immediately)
  useEffect(() => {
    setPaging({ hasMore: true, nextBefore: null, loading: true })
    loadMessages(conversation.id)
      .then((p) => setPaging({ ...p, loading: false }))
      .catch(() => setPaging((s) => ({ ...s, loading: false })))
  }, [conversation.id])

  // read receipts while the chat is on screen
  useEffect(() => {
    const read = () => document.visibilityState === 'visible' && void markRead(conversation.id)
    read()
    document.addEventListener('visibilitychange', read)
    return () => document.removeEventListener('visibilitychange', read)
  }, [conversation.id, messages.length])

  // MSG-11: infinite scroll upward, keeping the scroll position
  const loadOlder = useCallback(async () => {
    if (!paging.hasMore || paging.loading || !online) return
    const oldest = messages.find((m) => m.id)?.id
    if (!oldest) return
    prevHeight.current = scroller.current?.scrollHeight ?? null
    setPaging((s) => ({ ...s, loading: true }))
    try {
      const p = await loadMessages(conversation.id, oldest)
      setPaging({ ...p, loading: false })
    } catch {
      setPaging((s) => ({ ...s, loading: false }))
    }
  }, [conversation.id, messages, paging.hasMore, paging.loading, online])

  useLayoutEffect(() => {
    const el = scroller.current
    if (!el) return
    if (prevHeight.current !== null) {
      el.scrollTop += el.scrollHeight - prevHeight.current
      prevHeight.current = null
    } else if (atBottom) {
      el.scrollTop = el.scrollHeight
    }
  }, [messages, atBottom])

  const onScroll = () => {
    const el = scroller.current!
    setAtBottom(el.scrollHeight - el.scrollTop - el.clientHeight < 80)
    if (el.scrollTop < 120) void loadOlder()
  }

  const remove = useMutation({
    mutationFn: deleteForEveryone,
    onError: (e) => toast.error(errorMessage(e)),
  })

  const actions: BubbleActions = useMemo(
    () => ({
      onReply: setReplyTo,
      onDelete: (m) => confirm('Delete this message for everyone?') && remove.mutate(m),
      onInfo: setInfoFor,
      onRetry: (m) => void retryMessage(m),
      onDiscard: (m) => void discardMessage(m),
      onOpenImage: setImage,
    }),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [],
  )

  const group = conversation.type !== 'direct'
  return (
    <div className="flex h-full min-h-0 flex-col bg-muted/30">
      <Header conversation={conversation} onInfo={() => setGroupInfo(true)} />
      <div ref={scroller} onScroll={onScroll} className="relative min-h-0 flex-1 overflow-y-auto py-2" role="log" aria-label={`Messages in ${conversation.title}`}>
        {paging.loading && (
          <div className="flex justify-center py-2">
            <Loader2 className="size-4 animate-spin text-muted-foreground" />
          </div>
        )}
        {!paging.hasMore && messages.length > 0 && <p className="py-2 text-center text-xs text-muted-foreground">Start of the conversation</p>}
        {messages.length === 0 && !paging.loading && <p className="p-8 text-center text-sm text-muted-foreground">No messages yet. Say hello 👋</p>}
        {messages.map((m, i) => {
          const prev = messages[i - 1]
          const newDay = !prev || new Date(prev.created_at).toDateString() !== new Date(m.created_at).toDateString()
          const showSender = group && (!prev || prev.sender?.id !== m.sender?.id || newDay || prev.type === 'system')
          return (
            <Fragment key={m.client_uuid}>
              {newDay && <DateSeparator date={m.created_at} />}
              <MessageBubble message={m} mine={m.sender?.id === me.id} showSender={showSender} actions={actions} />
            </Fragment>
          )
        })}
      </div>
      {!atBottom && (
        <Button
          size="icon"
          variant="secondary"
          className="absolute right-4 bottom-24 z-20 size-10 rounded-full shadow-md"
          aria-label="Scroll to latest"
          onClick={() => scroller.current?.scrollTo({ top: scroller.current.scrollHeight, behavior: 'smooth' })}
        >
          <ArrowDown />
        </Button>
      )}
      <Composer conversation={conversation} replyTo={replyTo} onCancelReply={() => setReplyTo(null)} onSent={() => setAtBottom(true)} />
      <MessageInfoSheet message={infoFor} onClose={() => setInfoFor(null)} />
      {groupInfo && <GroupInfoSheet conversationId={conversation.id} onClose={() => setGroupInfo(false)} />}
      <Lightbox image={image} onClose={() => setImage(null)} />
    </div>
  )
}
