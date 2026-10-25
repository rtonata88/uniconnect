import { useLiveQuery } from 'dexie-react-hooks'
import { useEffect } from 'react'
import { EmptyState } from '@/components/common'
import type { CourseTabProps } from '@/features/courses/CoursePage'
import { db } from '@/lib/db'
import { ConversationView } from './components/ConversationView'
import { refreshConversation } from './data'
import { useChatStore } from './store'
import type { Conversation } from './types'

/** MSG-4: the course group chat inside the course page. */
export function CourseChatTab({ course }: CourseTabProps) {
  const id = course.conversation_id ?? 0
  const conversation = useLiveQuery(() => (id ? db.conversations.get(id) : undefined), [id]) as Conversation | undefined
  const setActive = useChatStore((s) => s.setActive)
  useEffect(() => {
    if (!id) return
    setActive(id)
    void refreshConversation(id).catch(() => undefined)
    return () => setActive(null)
  }, [id, setActive])
  if (!id) return <EmptyState title="No course chat" />
  if (!conversation) return <EmptyState title="Loading chat…" />
  return (
    <div className="relative h-[70svh] overflow-hidden rounded-lg border">
      <ConversationView conversation={conversation} />
    </div>
  )
}
