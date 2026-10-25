import { api } from '@/lib/api'

/**
 * Creating announcements goes through this indirection so phase 7 can route it
 * through the offline outbox (MOB-5: announcement drafts). Returns 'queued' when deferred.
 */
export const announcementWriter = {
  create: async (courseId: number, body: { title: string; body: string; publish: boolean }): Promise<unknown> =>
    api.post(`/courses/${courseId}/announcements`, { ...body, client_uuid: crypto.randomUUID() }),
}
