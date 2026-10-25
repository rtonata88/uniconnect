import axios from 'axios'
import { api } from '@/lib/api'
import { db } from '@/lib/db'
import { isOnline, setOnline } from '@/lib/useOnline'
import type { Announcement, Assignment, Course, Material, Submission } from '@/features/courses/types'
import type { MyGrades } from '@/features/grades/GradesTab'

/**
 * Read path (PRD 9.5): online → fetch, store in IndexedDB, return; offline (or the
 * request fails without a response) → read the local copy. The UI shows
 * "Offline – showing saved data" whenever isOnline() is false.
 */
export class NotAvailableOffline extends Error {
  constructor() {
    super('Not available offline yet. Connect once to save it on this device.')
  }
}

async function onlineFirst<T>(remote: () => Promise<T>, local: () => Promise<T | undefined>): Promise<T> {
  if (isOnline()) {
    try {
      return await remote()
    } catch (e) {
      if (!(axios.isAxiosError(e) && !e.response)) throw e
      setOnline(false)
    }
  }
  const value = await local()
  if (value === undefined) throw new NotAvailableOffline()
  return value
}

/* eslint-disable @typescript-eslint/no-explicit-any */
const byCode = (a: Course, b: Course) => a.code.localeCompare(b.code)

async function pendingSubmissions(): Promise<Map<number, Submission>> {
  const entries = await db.outbox.where('type').equals('submission').toArray()
  const map = new Map<number, Submission>()
  for (const e of entries) {
    const p = e.payload as { assignment_id: number; text: string | null; client_submitted_at: string }
    map.set(p.assignment_id, {
      id: 0,
      assignment_id: p.assignment_id,
      client_uuid: e.client_uuid,
      text: p.text,
      attachment: e.file_name ? { id: 0, name: e.file_name, mime: '', size: 0, is_image: false, url: '' } : null,
      submitted_at: p.client_submitted_at,
      is_late: false,
      attempt: 0,
      is_graded: false,
      score: null,
      final_score: null,
      feedback: null,
      graded_at: null,
      grade_released: false,
      updated_at: p.client_submitted_at,
      pending: true,
    })
  }
  return map
}

/** Attach my submission (server copy or the one still waiting in the outbox). */
async function withMySubmission(assignments: Assignment[]): Promise<Assignment[]> {
  const pending = await pendingSubmissions()
  const ids = assignments.map((a) => a.id)
  const stored = new Map(((await db.mySubmissions.where('assignment_id').anyOf(ids).toArray()) as Submission[]).map((s) => [s.assignment_id, s]))
  return assignments.map((a) => ({ ...a, my_submission: pending.get(a.id) ?? a.my_submission ?? stored.get(a.id) ?? null }))
}

async function storeAssignments(list: Assignment[]) {
  await db.assignments.bulkPut(list.map(({ my_submission: _, ...a }) => a) as any)
  const subs = list.map((a) => a.my_submission).filter((s): s is Submission => !!s && !!s.id)
  if (subs.length) await db.mySubmissions.bulkPut(subs as any)
}

async function pendingAnnouncements(courseId: number): Promise<Announcement[]> {
  const entries = await db.outbox.where('type').equals('announcement').toArray()
  return entries
    .filter((e) => (e.payload as { course_id: number }).course_id === courseId)
    .map((e) => {
      const p = e.payload as { course_id: number; title: string; body: string }
      const at = new Date(e.created_at).toISOString()
      return { id: -e.id!, course_id: courseId, client_uuid: e.client_uuid, title: p.title, body: p.body, author: null, published_at: null, created_at: at, updated_at: at, pending: true }
    })
}

export const repo = {
  async getCourses(): Promise<Course[]> {
    return onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Course[] }>('/courses')
        await db.transaction('rw', db.courses, async () => {
          await db.courses.clear()
          await db.courses.bulkPut(data.data as any)
        })
        return data.data
      },
      async () => ((await db.courses.toArray()) as Course[]).sort(byCode),
    )
  },

  async getCourse(id: number): Promise<Course> {
    return onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Course }>(`/courses/${id}`)
        await db.courses.put(data.data as any)
        return data.data
      },
      async () => (await db.courses.get(id)) as Course | undefined,
    )
  },

  async getMaterials(courseId: number): Promise<Material[]> {
    return onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Material[] }>(`/courses/${courseId}/materials`)
        await db.transaction('rw', db.materials, async () => {
          await db.materials.where('course_id').equals(courseId).delete()
          await db.materials.bulkPut(data.data as any)
        })
        return data.data
      },
      async () => {
        const rows = (await db.materials.where('course_id').equals(courseId).toArray()) as Material[]
        return (await db.courses.get(courseId)) ? rows.sort((a, b) => a.week - b.week || a.id - b.id) : undefined
      },
    )
  },

  async getAnnouncements(courseId: number): Promise<Announcement[]> {
    const pending = await pendingAnnouncements(courseId)
    const list = await onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Announcement[] }>(`/courses/${courseId}/announcements`)
        await db.transaction('rw', db.announcements, async () => {
          await db.announcements.where('course_id').equals(courseId).delete()
          await db.announcements.bulkPut(data.data as any)
        })
        return data.data
      },
      async () => {
        const rows = (await db.announcements.where('course_id').equals(courseId).toArray()) as Announcement[]
        return (await db.courses.get(courseId)) ? rows.sort((a, b) => (b.published_at ?? '').localeCompare(a.published_at ?? '')) : undefined
      },
    )
    return [...pending, ...list]
  },

  async getAssignments(courseId: number): Promise<Assignment[]> {
    const list = await onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Assignment[] }>(`/courses/${courseId}/assignments`)
        await storeAssignments(data.data)
        return data.data
      },
      async () => {
        const rows = (await db.assignments.where('course_id').equals(courseId).toArray()) as Assignment[]
        return (await db.courses.get(courseId)) ? rows.sort((a, b) => a.due_at.localeCompare(b.due_at)) : undefined
      },
    )
    return withMySubmission(list)
  },

  async getAssignment(id: number): Promise<Assignment> {
    const a = await onlineFirst(
      async () => {
        const { data } = await api.get<{ data: Assignment }>(`/assignments/${id}`)
        await storeAssignments([data.data])
        return data.data
      },
      async () => (await db.assignments.get(id)) as Assignment | undefined,
    )
    return (await withMySubmission([a]))[0]
  },

  async getMyGrades(courseId: number): Promise<MyGrades> {
    return onlineFirst(
      async () => {
        const { data } = await api.get<MyGrades>(`/courses/${courseId}/my-grades`)
        await db.transaction('rw', db.grades, async () => {
          await db.grades.where('course_id').equals(courseId).delete()
          await db.grades.bulkPut(
            data.items.map((i) => ({ ...i, grade_item_id: i.id, score: i.grade?.score ?? null, comment: i.grade?.comment ?? null })) as any,
          )
        })
        return data
      },
      async () => {
        if (!(await db.courses.get(courseId))) return undefined
        const rows = (await db.grades.where('course_id').equals(courseId).toArray()) as any[]
        const items = rows.map((r) => ({ ...r, grade: r.score === null && r.comment === null ? null : { id: r.id, score: r.score, max_score: r.max_score, comment: r.comment } }))
        return { items, weighted_total: weightedTotal(items) }
      },
    )
  },
}

/** Same formula as the server (D32): weights of graded items, equal weights if none set. */
export function weightedTotal(items: { weight_percent: number; max_score: number; grade: { score: number | null } | null }[]): number | null {
  const parts = items.filter((i) => i.grade?.score != null && i.max_score).map((i) => ({ pct: (i.grade!.score! / i.max_score) * 100, w: i.weight_percent }))
  if (!parts.length) return null
  const w = parts.reduce((s, p) => s + p.w, 0)
  const total = w > 0 ? parts.reduce((s, p) => s + p.pct * p.w, 0) / w : parts.reduce((s, p) => s + p.pct, 0) / parts.length
  return Math.round(total * 100) / 100
}
/* eslint-enable @typescript-eslint/no-explicit-any */
