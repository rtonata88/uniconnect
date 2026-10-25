import type { Attachment } from '@/features/courses/types'

export type TickStatus = 'pending' | 'failed' | 'sent' | 'delivered' | 'read'

export interface Message {
  id?: number
  conversation_id: number
  client_uuid: string
  type: 'text' | 'file' | 'image' | 'system'
  sender: { id: number; name: string } | null
  body: string | null
  attachment: Attachment | null
  reply_to: { id: number; sender_name: string | null; type: string; excerpt: string | null } | null
  deleted: boolean
  /** server tick status for my own messages */
  status: 'sent' | 'delivered' | 'read' | null
  read_count?: number | null
  recipient_count?: number | null
  created_at: string
  updated_at?: string
  // ---- local-only fields ----
  local_status?: 'pending' | 'failed'
  local_error?: string
  /** files row key of a pending upload */
  local_file_key?: string
  local_file?: { name: string; mime: string; size: number }
  upload_progress?: number
  /** receipts already queued for this message ('delivered' then 'read') */
  acked?: 'delivered' | 'read'
}

export interface Participant {
  id: number
  name: string
  role: 'admin' | 'member'
  avatar_url: string | null
  last_seen_at: string | null
}

export interface Conversation {
  id: number
  type: 'direct' | 'group' | 'course'
  title: string
  course_id: number | null
  announcements_only: boolean
  /** versioned realtime channel, e.g. conversation.12.3 (rotates when a member is removed) */
  channel: string
  avatar_url: string | null
  other_user: { id: number; name: string; last_seen_at: string | null } | null
  my_role: 'admin' | 'member' | null
  can_post: boolean
  muted_until: string | null
  last_read_message_id: number | null
  unread_count: number
  participant_count: number
  participants?: Participant[]
  last_message: { id: number; type: string; sender_id: number | null; sender_name: string | null; preview: string; created_at: string } | null
  last_message_at: string
  updated_at: string
}

export function tickOf(m: Message): TickStatus {
  if (m.local_status) return m.local_status
  return m.status ?? 'sent'
}
