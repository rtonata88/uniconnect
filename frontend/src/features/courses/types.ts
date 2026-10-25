import type { User } from '@/features/auth/types'

export interface Attachment {
  id: number
  name: string
  mime: string
  size: number
  is_image: boolean
  sha256?: string
  url: string
}

export interface Course {
  id: number
  code: string
  title: string
  description: string | null
  semester: string | null
  my_role: 'lecturer' | 'student' | null
  lecturers?: { id: number; name: string }[]
  students_count?: number
  conversation_id?: number | null
  updated_at: string
  deleted_at?: string | null
}

export interface Material {
  id: number
  course_id: number
  title: string
  description: string | null
  week: number
  attachment: Attachment | null
  published_at: string | null
  created_at: string
  updated_at: string
}

export interface Announcement {
  id: number
  course_id: number
  client_uuid?: string | null
  title: string
  body: string
  author: { id: number; name: string } | null
  published_at: string | null
  created_at: string
  updated_at: string
  /** local-only: queued in the outbox */
  pending?: boolean
}

export interface Submission {
  id: number
  assignment_id: number
  client_uuid: string
  student?: { id: number; name: string; student_number?: string | null }
  text: string | null
  attachment: Attachment | null
  submitted_at: string
  is_late: boolean
  attempt: number
  is_graded: boolean
  score: number | null
  final_score: number | null
  feedback: string | null
  graded_at: string | null
  grade_released: boolean
  updated_at: string
  /** local-only: queued in the outbox */
  pending?: boolean
}

export interface Assignment {
  id: number
  course_id: number
  title: string
  instructions: string | null
  due_at: string
  max_score: number
  allow_late: boolean
  late_penalty_percent: number
  attachment: Attachment | null
  published_at: string | null
  submissions_count?: number
  my_submission?: Submission | null
  created_at: string
  updated_at: string
}

export interface People {
  lecturers: User[]
  students: User[]
}

export type SubmissionStatus = 'on_time' | 'late' | 'missing' | 'not_submitted'

export interface SubmissionRow {
  student: User
  status: SubmissionStatus
  submission: Submission | null
}
