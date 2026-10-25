import { api } from '@/lib/api'
import type { Attachment } from '@/features/courses/types'

export function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

export const MAX_UPLOAD_BYTES = 20 * 1024 * 1024

/** Upload a file to POST /attachments with progress (MSG-6, LMS-3). */
export async function uploadFile(file: Blob, name: string, onProgress?: (pct: number) => void): Promise<Attachment> {
  if (file.size > MAX_UPLOAD_BYTES) throw new Error('Files may be at most 20 MB.')
  const body = new FormData()
  body.append('file', file, name)
  const { data } = await api.post<{ data: Attachment }>('/attachments', body, {
    timeout: 120_000,
    onUploadProgress: (e) => onProgress?.(e.total ? Math.round((e.loaded / e.total) * 100) : 0),
  })
  return data.data
}

/** Short-lived signed URL (SEC-4). Tokens can't be sent by <img>/<a>, so ask for JSON. */
export async function signedUrl(attachmentId: number): Promise<string> {
  const { data } = await api.get<{ url: string }>(`/attachments/${attachmentId}`, { params: { json: 1 } })
  return data.url
}

/** Download the bytes (for offline storage). */
export async function downloadBlob(attachmentId: number): Promise<Blob> {
  const url = await signedUrl(attachmentId)
  const res = await fetch(url)
  if (!res.ok) throw new Error(`Download failed (${res.status})`)
  return res.blob()
}

/** Open a file in a new tab (PDFs and images open inline in the browser viewer). */
export async function openAttachment(attachment: Pick<Attachment, 'id'>) {
  // open the window synchronously so popup blockers allow it, then point it at the signed URL
  const win = window.open('', '_blank')
  try {
    const url = await signedUrl(attachment.id)
    if (win) win.location.href = url
    else window.location.href = url
  } catch (e) {
    win?.close()
    throw e
  }
}

export function fileKind(mime: string): 'pdf' | 'image' | 'doc' | 'slides' | 'sheet' | 'file' {
  if (mime === 'application/pdf') return 'pdf'
  if (mime.startsWith('image/')) return 'image'
  if (mime.includes('word')) return 'doc'
  if (mime.includes('presentation') || mime.includes('powerpoint')) return 'slides'
  if (mime.includes('sheet') || mime === 'text/csv') return 'sheet'
  return 'file'
}
