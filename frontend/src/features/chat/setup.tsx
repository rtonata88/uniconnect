import { useLiveQuery } from 'dexie-react-hooks'
import { MessageCircle } from 'lucide-react'
import { useEffect } from 'react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { shellSlots } from '@/app/shellSlots'
import { Button } from '@/components/ui/button'
import { onAuthChange, useAuth } from '@/features/auth/store'
import type { User } from '@/features/auth/types'
import { peopleActions } from '@/features/courses/tabs/peopleActions'
import { api, errorMessage } from '@/lib/api'
import { db } from '@/lib/db'
import { processOutbox } from '@/lib/sync/outbox'
import { startRealtime, stopRealtime } from './realtime'
import type { Conversation } from './types'

/** Starts sockets once signed in and stops them on logout. Mounted once in the shell. */
export function useRealtime() {
  const user = useAuth((s) => s.user)
  useEffect(() => {
    if (!user) return
    startRealtime(user.id).catch(() => undefined)
    void processOutbox()
  }, [user?.id]) // eslint-disable-line react-hooks/exhaustive-deps
}

onAuthChange((user) => {
  if (!user) stopRealtime()
})

/** Unread chats badge (MOB-1 bottom tab / sidebar). */
function useChatBadge() {
  return (
    useLiveQuery(async () => (await db.conversations.toArray()).filter((c) => (c as Conversation).unread_count > 0).length, []) ?? 0
  )
}

function MessageAction({ user }: { user: User }) {
  const me = useAuth((s) => s.user)
  const navigate = useNavigate()
  if (!me || me.id === user.id) return null
  return (
    <Button
      variant="ghost"
      size="icon"
      className="size-10"
      aria-label={`Message ${user.name}`}
      onClick={async () => {
        try {
          const { data } = await api.post<{ data: Conversation }>('/conversations', { type: 'direct', user_id: user.id })
          await db.conversations.put(data.data)
          navigate(`/chats/${data.data.id}`)
        } catch (e) {
          toast.error(errorMessage(e))
        }
      }}
    >
      <MessageCircle />
    </Button>
  )
}

const previousBadges = shellSlots.useBadges
shellSlots.useBadges = () => ({ ...previousBadges(), chats: useChatBadge() })
peopleActions.push(MessageAction)
