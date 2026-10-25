import axios from 'axios'
import { api } from '@/lib/api'
import { db, type OutboxRow } from '@/lib/db'
import { socketId } from '@/lib/echo'
import { uploadFile } from '@/lib/files'
import { enqueue, onEntryFailed, processOutbox, registerHandler } from '@/lib/sync/outbox'
import { useAuth } from '@/features/auth/store'
import type { Conversation, Message } from './types'

/**
 * Chat data layer. IndexedDB (Dexie) is the source the UI renders from (useLiveQuery);
 * the server is the source of truth (PRD 9.4). Sends go through the outbox (PRD 5.3).
 */

const me = () => useAuth.getState().user?.id

// ---------------------------------------------------------------- conversations

export async function refreshConversations() {
  const { data } = await api.get<{ data: Conversation[] }>('/conversations')
  await db.transaction('rw', db.conversations, async () => {
    const keep = new Set(data.data.map((c) => c.id))
    const stale = (await db.conversations.toCollection().primaryKeys()).filter((id) => !keep.has(id as number))
    await db.conversations.bulkDelete(stale)
    await db.conversations.bulkPut(data.data)
  })
  return data.data
}

export async function refreshConversation(id: number): Promise<Conversation | null> {
  try {
    const { data } = await api.get<{ data: Conversation }>(`/conversations/${id}`)
    await db.conversations.put(data.data)
    return data.data
  } catch (e) {
    if (axios.isAxiosError(e) && (e.response?.status === 403 || e.response?.status === 404)) {
      await removeConversationLocally(id)
      return null
    }
    throw e
  }
}

export async function removeConversationLocally(id: number) {
  await db.transaction('rw', db.conversations, db.messages, async () => {
    await db.conversations.delete(id)
    await db.messages.where('conversation_id').equals(id).delete()
  })
}

// ---------------------------------------------------------------- messages

/**
 * Store messages from the server (history, real time or sync). Keeps local receipt
 * bookkeeping and queues delivered receipts for messages from others (PRD 5.3 step 5).
 */
export async function storeServerMessages(messages: Message[], opts: { ack?: boolean } = {}) {
  if (!messages.length) return
  const myId = me()
  const toDeliver: number[] = []
  await db.transaction('rw', db.messages, async () => {
    const existing = await db.messages.bulkGet(messages.map((m) => m.client_uuid))
    const rows = messages.map((m, i) => {
      const prev = existing[i] as Message | undefined
      const fromOther = m.sender && m.sender.id !== myId
      const acked = prev?.acked ?? (fromOther && opts.ack !== false ? 'delivered' : undefined)
      if (fromOther && !prev?.acked && opts.ack !== false && m.id && !m.deleted) toDeliver.push(m.id)
      // keep a known tick status if the server row came without one (e.g. via broadcast)
      return { ...m, status: m.status ?? prev?.status ?? null, acked, local_status: undefined, local_error: undefined }
    })
    await db.messages.bulkPut(rows)
  })
  if (toDeliver.length) await queueReceipts(toDeliver, [])
}

export async function loadMessages(conversationId: number, before?: number) {
  const { data } = await api.get<{ data: Message[]; has_more: boolean; next_before: number | null }>(
    `/conversations/${conversationId}/messages`,
    { params: { before, limit: 50 } },
  )
  await storeServerMessages(data.data)
  return { hasMore: data.has_more, nextBefore: data.next_before }
}

/** Patch the chat list preview for a new message (local or server). */
async function bumpConversation(m: Message, fromMe: boolean) {
  const conv = (await db.conversations.get(m.conversation_id)) as Conversation | undefined
  if (!conv) return
  const isNewer = !conv.last_message || (m.id ?? Infinity) >= conv.last_message.id || !m.id
  if (!isNewer) return
  await db.conversations.update(m.conversation_id, {
    last_message: {
      id: m.id ?? conv.last_message?.id ?? 0,
      type: m.type,
      sender_id: m.sender?.id ?? null,
      sender_name: m.sender?.name ?? null,
      preview: m.deleted ? 'This message was deleted' : (m.body ?? (m.type === 'image' ? 'Photo' : (m.attachment?.name ?? m.local_file?.name ?? ''))),
      created_at: m.created_at,
    },
    last_message_at: m.created_at,
    unread_count: fromMe ? conv.unread_count : conv.unread_count + (m.type === 'system' ? 0 : 1),
  })
}

/** A message arrived over the socket (MSG-5). */
export async function receiveMessage(m: Message, opts: { visible: boolean }) {
  const fromMe = m.sender?.id === me()
  await storeServerMessages([m])
  await bumpConversation(m, fromMe)
  if (opts.visible && !fromMe && m.id) await markRead(m.conversation_id)
}

export interface SendInput {
  conversation: Pick<Conversation, 'id'>
  body?: string
  file?: File | Blob | null
  fileName?: string
  replyTo?: Message | null
}

/** Optimistic send: shows a clock icon at once, then the outbox delivers it (PRD 5.3 step 1). */
export async function sendMessage({ conversation, body, file, fileName, replyTo }: SendInput) {
  const user = useAuth.getState().user!
  const client_uuid = crypto.randomUUID()
  const name = fileName ?? (file instanceof File ? file.name : 'file')
  let fileKey: string | undefined
  let fileRowId: number | undefined
  if (file) {
    fileKey = `pending:${client_uuid}`
    fileRowId = await db.files.add({ key: fileKey, blob: file, name, mime: file.type || 'application/octet-stream', size: file.size, saved_at: Date.now() })
  }
  const local: Message = {
    conversation_id: conversation.id,
    client_uuid,
    type: file ? (file.type.startsWith('image/') ? 'image' : 'file') : 'text',
    sender: { id: user.id, name: user.name },
    body: body?.trim() || null,
    attachment: null,
    reply_to: replyTo?.id ? { id: replyTo.id, sender_name: replyTo.sender?.name ?? null, type: replyTo.type, excerpt: replyTo.body ?? replyTo.attachment?.name ?? null } : null,
    deleted: false,
    status: null,
    created_at: new Date().toISOString(),
    local_status: 'pending',
    local_file_key: fileKey,
    local_file: file ? { name, mime: file.type, size: file.size } : undefined,
  }
  await db.messages.put(local)
  await bumpConversation(local, true)
  await enqueue({
    client_uuid,
    type: 'message',
    payload: { conversation_id: conversation.id, body: local.body, reply_to_id: replyTo?.id ?? null },
    file_blob_id: fileRowId,
    file_name: name,
    scope: `conversation:${conversation.id}`,
  })
  return local
}

registerHandler('message', async (entry: OutboxRow) => {
  const p = entry.payload as { conversation_id: number; body: string | null; reply_to_id: number | null }
  let attachment_id: number | null = (entry.payload.attachment_id as number | undefined) ?? null
  if (entry.file_blob_id && !attachment_id) {
    const file = await db.files.get(entry.file_blob_id)
    if (file) {
      const attachment = await uploadFile(file.blob, entry.file_name ?? file.name, (pct) => {
        void db.messages.update(entry.client_uuid, { upload_progress: pct })
      })
      attachment_id = attachment.id
      // remember the upload so a retry of the POST does not upload again
      await db.outbox.update(entry.id!, { payload: { ...entry.payload, attachment_id } })
    }
  }
  const sid = socketId()
  const { data } = await api.post<{ data: Message }>(
    `/conversations/${p.conversation_id}/messages`,
    { client_uuid: entry.client_uuid, body: p.body, attachment_id, reply_to_id: p.reply_to_id },
    { headers: sid ? { 'X-Socket-ID': sid } : {} },
  )
  // same client_uuid → replaces the pending row: clock becomes one grey tick
  await db.messages.put({ ...data.data, acked: undefined })
  if (entry.file_blob_id) await db.files.delete(entry.file_blob_id)
  await bumpConversation(data.data, true)
})

onEntryFailed(async (entry, message) => {
  if (entry.type === 'message') await db.messages.update(entry.client_uuid, { local_status: 'failed', local_error: message })
})

export async function retryMessage(m: Message) {
  await db.messages.update(m.client_uuid, { local_status: 'pending', local_error: undefined })
  const { retry } = await import('@/lib/sync/outbox')
  await retry(m.client_uuid)
}

export async function discardMessage(m: Message) {
  const { discard } = await import('@/lib/sync/outbox')
  await discard(m.client_uuid)
  await db.messages.delete(m.client_uuid)
  if (m.local_file_key) await db.files.where('key').equals(m.local_file_key).delete()
}

export async function deleteForEveryone(m: Message) {
  await api.delete(`/messages/${m.id}`)
  await markDeleted(m.id!)
}

export async function markDeleted(id: number) {
  const row = await db.messages.where('id').equals(id).first()
  if (row) await db.messages.update(row.client_uuid, { deleted: true, body: null, attachment: null })
}

// ---------------------------------------------------------------- receipts (MSG-7)

/** Receipts are batched into one outbox entry so a burst of messages costs one request. */
export async function queueReceipts(delivered: number[], read: number[]) {
  if (!delivered.length && !read.length) return
  const pending = await db.outbox.where('type').equals('receipt').filter((e) => e.status === 'pending' && !e.attempts).first()
  if (pending) {
    const p = pending.payload as { delivered: number[]; read: number[] }
    await db.outbox.update(pending.id!, {
      payload: { delivered: [...new Set([...p.delivered, ...delivered])], read: [...new Set([...p.read, ...read])] },
    })
    void processOutbox()
  } else {
    await enqueue({ client_uuid: crypto.randomUUID(), type: 'receipt', payload: { delivered, read } })
  }
}

registerHandler('receipt', async (entry) => {
  await api.post('/messages/receipts', entry.payload)
})

/** Mark everything from others in this chat as read (PRD 5.3 step 6). */
export async function markRead(conversationId: number) {
  const myId = me()
  const unread = (await db.messages
    .where('conversation_id')
    .equals(conversationId)
    .filter((m) => !!m.id && m.sender?.id !== myId && m.acked !== 'read' && m.type !== 'system' && !m.deleted)
    .toArray()) as Message[]
  await db.conversations.update(conversationId, { unread_count: 0 })
  if (!unread.length) return
  await db.messages.bulkUpdate(unread.map((m) => ({ key: m.client_uuid, changes: { acked: 'read' } })))
  const maxId = Math.max(...unread.map((m) => m.id!))
  await db.conversations.update(conversationId, { last_read_message_id: maxId })
  await queueReceipts([], unread.map((m) => m.id!))
}

/** Sender side: ticks changed (ReceiptsUpdated on private-user.{me}). */
export async function applyReceipts(receipts: { message_id: number; status: Message['status']; read: number; recipients: number }[]) {
  for (const r of receipts) {
    const row = await db.messages.where('id').equals(r.message_id).first()
    if (row) await db.messages.update(row.client_uuid, { status: r.status, read_count: r.read, recipient_count: r.recipients })
  }
}
