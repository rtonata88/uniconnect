import type { Role } from '@/app/nav'

export interface User {
  id: number
  name: string
  email?: string
  role: Role
  student_number?: string | null
  avatar_url?: string | null
  last_seen_at?: string | null
  is_active?: boolean
  created_at?: string
}

export interface CourseSummary {
  id: number
  code: string
  title: string
  role: 'lecturer' | 'student'
}

export interface Me extends User {
  email: string
  notification_preferences: Record<string, boolean>
  courses?: CourseSummary[]
}

export interface LoginResponse {
  token: string
  expires_at: string
  user: Me
}
