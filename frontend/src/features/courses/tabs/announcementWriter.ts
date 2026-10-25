import axios from 'axios'
import { queryClient } from '@/app/providers'
import { api } from '@/lib/api'
import { enqueue, registerHandler } from '@/lib/sync/outbox'
import { isOnline, setOnline } from '@/lib/useOnline'

/**
 * MOB-5: announcement drafts written offline wait in the outbox and are posted when the
 * connection returns (idempotent on client_uuid). Returns 'queued' when deferred.
 */
export const announcementWriter = {
  create: async (courseId: number, body: { title: string; body: string; publish: boolean }): Promise<unknown> => {
    const client_uuid = crypto.randomUUID()
    if (isOnline()) {
      try {
        return await api.post(`/courses/${courseId}/announcements`, { ...body, client_uuid })
      } catch (e) {
        if (!(axios.isAxiosError(e) && !e.response)) throw e
        setOnline(false)
      }
    }
    await enqueue({ client_uuid, type: 'announcement', payload: { course_id: courseId, ...body }, scope: `course:${courseId}` })
    return 'queued'
  },
}

registerHandler('announcement', async (entry) => {
  const p = entry.payload as { course_id: number; title: string; body: string; publish: boolean }
  await api.post(`/courses/${p.course_id}/announcements`, { title: p.title, body: p.body, publish: p.publish, client_uuid: entry.client_uuid })
  void queryClient.invalidateQueries({ queryKey: ['courses', p.course_id, 'announcements'] })
})
