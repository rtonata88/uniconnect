import { db } from '@/lib/db'
import { downloadBlob } from '@/lib/files'
import type { Attachment } from '@/features/courses/types'

/** LMS-3 / MOB-4: materials saved on the device and opened from IndexedDB. */
export const offlineKey = (attachmentId: number) => `attachment:${attachmentId}`

export async function saveOffline(attachment: Attachment) {
  const blob = await downloadBlob(attachment.id)
  await db.files.put({ key: offlineKey(attachment.id), blob, name: attachment.name, mime: attachment.mime, size: blob.size, saved_at: Date.now() })
}

export async function removeOffline(attachmentId: number) {
  await db.files.where('key').equals(offlineKey(attachmentId)).delete()
}

export async function openOffline(attachmentId: number): Promise<boolean> {
  const row = await db.files.where('key').equals(offlineKey(attachmentId)).first()
  if (!row) return false
  const url = URL.createObjectURL(row.mime ? new Blob([row.blob], { type: row.mime }) : row.blob)
  const opened = window.open(url, '_blank')
  if (!opened) {
    // installed PWAs on some phones block popups: download instead
    const a = Object.assign(document.createElement('a'), { href: url, download: row.name })
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(url), 60_000)
  return true
}
