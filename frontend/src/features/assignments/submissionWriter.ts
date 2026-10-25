import { api } from '@/lib/api'
import { uploadFile } from '@/lib/files'
import type { Submission } from '@/features/courses/types'

export interface SubmissionDraft {
  assignmentId: number
  courseId: number
  text: string
  file: File | null
  onProgress?: (pct: number) => void
}

/**
 * Submitting goes through this indirection so phase 7 can route it through the
 * offline outbox (MOB-5: submissions including the file blob).
 * Returns the stored submission, or 'queued' when deferred.
 */
export const submissionWriter = {
  submit: async (d: SubmissionDraft): Promise<Submission | 'queued'> => {
    const attachment_id = d.file ? (await uploadFile(d.file, d.file.name, d.onProgress)).id : null
    const { data } = await api.post<{ data: Submission }>(`/assignments/${d.assignmentId}/submissions`, {
      client_uuid: crypto.randomUUID(),
      text: d.text || null,
      attachment_id,
      client_submitted_at: new Date().toISOString(),
    })
    return data.data
  },
}
