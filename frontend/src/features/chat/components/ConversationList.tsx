import { useLiveQuery } from 'dexie-react-hooks'
import { BellOff, Megaphone, MessageSquarePlus, Search, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { NavLink } from 'react-router'
import { UserAvatar } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/store'
import { db } from '@/lib/db'
import { fmtShort } from '@/lib/format'
import { cn } from '@/lib/utils'
import { typingLabel, useChatStore } from '../store'
import type { Conversation } from '../types'

function Row({ c }: { c: Conversation }) {
  const me = useAuth((s) => s.user)?.id
  const online = useChatStore((s) => (c.other_user ? s.online.has(c.other_user.id) : undefined))
  const typing = typingLabel(useChatStore((s) => s.typing[c.id]))
  const muted = c.muted_until && new Date(c.muted_until) > new Date()
  const preview = c.last_message
    ? `${c.last_message.sender_id === me ? 'You: ' : c.type !== 'direct' && c.last_message.sender_name ? `${c.last_message.sender_name.split(' ')[0]}: ` : ''}${c.last_message.preview}`
    : 'No messages yet'
  return (
    <NavLink
      to={`/chats/${c.id}`}
      className={({ isActive }) => cn('flex min-h-16 items-center gap-3 px-3 py-2 hover:bg-muted/60', isActive && 'bg-muted')}
    >
      <UserAvatar user={{ name: c.title, avatar_url: c.avatar_url }} className="size-12" online={c.type === 'direct' ? online : undefined} />
      <div className="min-w-0 flex-1">
        <div className="flex items-baseline gap-2">
          <p className="flex min-w-0 flex-1 items-center gap-1 truncate font-medium">
            {c.type === 'group' && <Users className="size-3.5 shrink-0 text-muted-foreground" />}
            {c.type === 'course' && <Megaphone className="size-3.5 shrink-0 text-muted-foreground" />}
            <span className="truncate">{c.title}</span>
          </p>
          {c.last_message && <time className={cn('shrink-0 text-xs', c.unread_count ? 'text-primary' : 'text-muted-foreground')}>{fmtShort(c.last_message.created_at)}</time>}
        </div>
        <div className="flex items-center gap-2">
          <p className={cn('min-w-0 flex-1 truncate text-sm', typing ? 'text-primary' : 'text-muted-foreground')}>{typing ?? preview}</p>
          {muted && <BellOff className="size-3.5 shrink-0 text-muted-foreground" aria-label="muted" />}
          {c.unread_count > 0 && (
            <Badge className={cn('h-5 min-w-5 rounded-full px-1.5', muted && 'bg-muted-foreground')} aria-label={`${c.unread_count} unread`}>
              {c.unread_count}
            </Badge>
          )}
        </div>
      </div>
    </NavLink>
  )
}

/** MSG-1: all conversations, newest activity first, search by name. */
export function ConversationList({ onNewChat }: { onNewChat: () => void }) {
  const [search, setSearch] = useState('')
  const all = useLiveQuery(() => db.conversations.toArray()) as Conversation[] | undefined
  const list = useMemo(
    () =>
      (all ?? [])
        .filter((c) => c.title.toLowerCase().includes(search.toLowerCase()))
        .sort((a, b) => (b.last_message?.created_at ?? b.last_message_at).localeCompare(a.last_message?.created_at ?? a.last_message_at)),
    [all, search],
  )
  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex items-center gap-2 border-b p-2">
        <div className="relative flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input placeholder="Search chats" aria-label="Search chats" className="h-10 pl-9" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <Button size="icon" className="size-10" aria-label="New chat" onClick={onNewChat}>
          <MessageSquarePlus />
        </Button>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        {!all && Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="m-3 h-14" />)}
        {all && list.length === 0 && (
          <p className="p-6 text-center text-sm text-muted-foreground">{search ? 'No chats match your search.' : 'No chats yet. Start one with the + button.'}</p>
        )}
        {list.map((c) => (
          <Row key={c.id} c={c} />
        ))}
      </div>
    </div>
  )
}
