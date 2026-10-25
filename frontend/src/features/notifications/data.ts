import { api } from '@/lib/api'
import { db } from '@/lib/db'

export interface AppNotification {
  id: string
  category: 'announcements' | 'assignments' | 'quizzes' | 'grades' | 'reminders' | 'groups' | 'messages'
  title: string
  body: string
  url: string
  course_id?: number
  read_at: string | null
  created_at: string
}

/** Notification centre (NOT-1) cached in IndexedDB for offline reading. */
export async function refreshNotifications() {
  const { data } = await api.get<{ data: AppNotification[]; unread_count: number }>('/notifications')
  await db.transaction('rw', db.notifications, async () => {
    await db.notifications.clear()
    await db.notifications.bulkPut(data.data)
  })
  return data
}

export async function storeNotification(n: AppNotification) {
  await db.notifications.put({ ...n, read_at: n.read_at ?? null })
}

export async function markNotificationsRead(ids: string[] | 'all') {
  const now = new Date().toISOString()
  if (ids === 'all') {
    await db.notifications.toCollection().modify((n) => {
      n.read_at ??= now
    })
  } else {
    await db.notifications.where('id').anyOf(ids).modify((n) => {
      n.read_at ??= now
    })
  }
  try {
    await api.post('/notifications/read', ids === 'all' ? { all: true } : { ids })
  } catch {
    // offline: the next refresh reconciles with the server
  }
}
