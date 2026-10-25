import axios from 'axios'
import { queryClient } from '@/app/providers'
import { api } from '@/lib/api'
import { db } from '@/lib/db'
import { uploadFile } from '@/lib/files'
import { enqueue, registerHandler } from '@/lib/sync/outbox'
import { isOnline, setOnline } from '@/lib/useOnline'
import type { Submission } from '@/features/courses/types'

export interface SubmissionDraft {
  assignmentId: number
  courseId: number
  text: string
  file: File | null
  onProgress?: (pct: number) => void
}

const refresh = (assignmentId: number, courseId: number) => {
  void queryClient.invalidateQueries({ queryKey: ['assignments', assignmentId] })
  void queryClient.invalidateQueries({ queryKey: ['courses', courseId, 'assignments'] })
}

/**
 * MOB-5 / LMS-6: online the submission is sent straight away (validation errors show at once);
 * offline, or if the connection drops, it goes to the outbox with its file blob and is sent
 * automatically when the connection returns. The server decides lateness by receive time;
 * the device time travels along as client_submitted_at (audit).
 */
export const submissionWriter = {
  submit: async (d: SubmissionDraft): Promise<Submission | 'queued'> => {
    const client_uuid = crypto.randomUUID()
    const client_submitted_at = new Date().toISOString()
    if (isOnline()) {
      try {
        const attachment_id = d.file ? (await uploadFile(d.file, d.file.name, d.onProgress)).id : null
        const { data } = await api.post<{ data: Submission }>(`/assignments/${d.assignmentId}/submissions`, {
          client_uuid,
          text: d.text || null,
          attachment_id,
          client_submitted_at,
        })
        await db.mySubmissions.put(data.data as never)
        return data.data
      } catch (e) {
        if (!(axios.isAxiosError(e) && !e.response)) throw e
        setOnline(false) // fall through to the outbox
      }
    }
    const fileId = d.file
      ? await db.files.add({ key: `pending:${client_uuid}`, blob: d.file, name: d.file.name, mime: d.file.type, size: d.file.size, saved_at: Date.now() })
      : undefined
    await enqueue({
      client_uuid,
      type: 'submission',
      payload: { assignment_id: d.assignmentId, course_id: d.courseId, text: d.text || null, client_submitted_at },
      file_blob_id: fileId,
      file_name: d.file?.name,
      scope: `assignment:${d.assignmentId}`,
    })
    refresh(d.assignmentId, d.courseId)
    return 'queued'
  },
}

registerHandler('submission', async (entry) => {
  const p = entry.payload as { assignment_id: number; course_id: number; text: string | null; client_submitted_at: string; attachment_id?: number }
  let attachment_id = p.attachment_id ?? null
  if (entry.file_blob_id && !attachment_id) {
    const file = await db.files.get(entry.file_blob_id)
    if (file) {
      attachment_id = (await uploadFile(file.blob, entry.file_name ?? file.name)).id
      await db.outbox.update(entry.id!, { payload: { ...p, attachment_id } })
    }
  }
  const { data } = await api.post<{ data: Submission }>(`/assignments/${p.assignment_id}/submissions`, {
    client_uuid: entry.client_uuid,
    text: p.text,
    attachment_id,
    client_submitted_at: p.client_submitted_at,
  })
  await db.mySubmissions.put(data.data as never)
  if (entry.file_blob_id) await db.files.delete(entry.file_blob_id)
  refresh(p.assignment_id, p.course_id)
})
