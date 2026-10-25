import { useLiveQuery } from 'dexie-react-hooks'
import { MessageCircle } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import { db } from '@/lib/db'
import { cn } from '@/lib/utils'
import { ConversationList } from './components/ConversationList'
import { ConversationView } from './components/ConversationView'
import { NewChatDialog } from './components/NewChatDialog'
import { refreshConversation } from './data'
import { useChatStore } from './store'
import type { Conversation } from './types'

/** MOB-1: two panes on desktop (like WhatsApp Web), one at a time on phones. */
export function ChatsPage() {
  const id = Number(useParams().conversationId ?? 0)
  const [creating, setCreating] = useState(false)
  const conversation = useLiveQuery(() => (id ? db.conversations.get(id) : undefined), [id]) as Conversation | undefined
  const setActive = useChatStore((s) => s.setActive)

  useEffect(() => {
    setActive(id || null)
    if (id) void refreshConversation(id).catch(() => undefined)
    return () => setActive(null)
  }, [id, setActive])

  return (
    <div className="flex h-[calc(100svh-3.5rem-4rem-env(safe-area-inset-bottom))] min-h-0 md:h-[calc(100svh-3.5rem)]">
      <aside className={cn('w-full border-r md:block md:w-80 lg:w-96', id ? 'hidden' : 'block')}>
        <ConversationList onNewChat={() => setCreating(true)} />
      </aside>
      <section className={cn('relative min-w-0 flex-1', id ? 'block' : 'hidden md:block')}>
        {conversation ? (
          <ConversationView key={conversation.id} conversation={conversation} />
        ) : (
          <div className="flex h-full flex-col items-center justify-center gap-2 text-muted-foreground">
            <MessageCircle className="size-10" />
            <p className="text-sm">{id ? 'Loading chat…' : 'Select a chat or start a new one.'}</p>
          </div>
        )}
      </section>
      <NewChatDialog open={creating} onOpenChange={setCreating} />
    </div>
  )
}
