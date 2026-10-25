import Dexie, { type EntityTable } from 'dexie'

/**
 * Local replica in IndexedDB (PRD 9.1). Records are stored as the API returns them;
 * only the indexed fields are declared here.
 */
export interface MetaRow {
  key: string
  value: unknown
}

export interface OutboxRow {
  id?: number
  client_uuid: string
  type: 'message' | 'submission' | 'receipt' | 'announcement'
  payload: Record<string, unknown>
  file_blob_id?: number
  file_name?: string
  created_at: number
  attempts: number
  status: 'pending' | 'sending' | 'failed'
  last_error?: string
  next_attempt_at?: number
  /** conversation / assignment / course the entry belongs to (for UI lookups) */
  scope?: string
}

export interface FileRow {
  id?: number
  /** "attachment:<id>" for downloaded files, "pending:<uuid>" for outbox uploads */
  key: string
  blob: Blob
  name: string
  mime: string
  size: number
  saved_at: number
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Row = Record<string, any> & { id: number }

export class UniDb extends Dexie {
  meta!: EntityTable<MetaRow, 'key'>
  courses!: EntityTable<Row, 'id'>
  materials!: EntityTable<Row, 'id'>
  announcements!: EntityTable<Row, 'id'>
  assignments!: EntityTable<Row, 'id'>
  mySubmissions!: EntityTable<Row, 'id'>
  grades!: EntityTable<Row, 'id'>
  conversations!: EntityTable<Row, 'id'>
  messages!: EntityTable<Record<string, any> & { client_uuid: string }, 'client_uuid'>
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  notifications!: EntityTable<Record<string, any> & { id: string }, 'id'>
  files!: EntityTable<FileRow, 'id'>
  outbox!: EntityTable<OutboxRow, 'id'>

  constructor(name = 'uniconnect') {
    super(name)
    this.version(1).stores({
      meta: 'key',
      courses: 'id, code',
      materials: 'id, course_id, week',
      announcements: 'id, course_id, published_at',
      assignments: 'id, course_id, due_at',
      mySubmissions: 'id, assignment_id, client_uuid',
      grades: 'id, course_id, grade_item_id',
      conversations: 'id, type, course_id, last_message_at',
      // keyed by client_uuid so messages still waiting in the outbox (no server id yet) can be stored
      messages: 'client_uuid, id, conversation_id, [conversation_id+id], [conversation_id+created_at]',
      notifications: 'id, created_at, read_at',
      files: '++id, &key',
      outbox: '++id, &client_uuid, status, type, scope',
    })
  }
}

export const db = new UniDb()

export async function getMeta<T>(key: string): Promise<T | undefined> {
  return (await db.meta.get(key))?.value as T | undefined
}

export async function setMeta(key: string, value: unknown) {
  await db.meta.put({ key, value })
}

/** Wipe every local table (logout). */
export async function clearLocalData() {
  await db.transaction('rw', db.tables, async () => {
    await Promise.all(db.tables.map((t) => t.clear()))
  })
}
