import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Announcement, Assignment, Course, Material, People, SubmissionRow } from './types'

export const courseKeys = {
  all: ['courses'] as const,
  detail: (id: number) => ['courses', id] as const,
  materials: (id: number) => ['courses', id, 'materials'] as const,
  announcements: (id: number) => ['courses', id, 'announcements'] as const,
  assignments: (id: number) => ['courses', id, 'assignments'] as const,
  people: (id: number) => ['courses', id, 'people'] as const,
  assignment: (id: number) => ['assignments', id] as const,
  submissions: (id: number) => ['assignments', id, 'submissions'] as const,
}

type Loader<T> = () => Promise<T>
/**
 * Read path indirection (PRD 9.5): phase 7 swaps these loaders for the offline
 * repository, which falls back to IndexedDB. Components only use the hooks below.
 */
export const loaders = {
  courses: (async () => (await api.get<{ data: Course[] }>('/courses')).data.data) as Loader<Course[]>,
  course: async (id: number) => (await api.get<{ data: Course }>(`/courses/${id}`)).data.data,
  materials: async (id: number) => (await api.get<{ data: Material[] }>(`/courses/${id}/materials`)).data.data,
  announcements: async (id: number) => (await api.get<{ data: Announcement[] }>(`/courses/${id}/announcements`)).data.data,
  assignments: async (id: number) => (await api.get<{ data: Assignment[] }>(`/courses/${id}/assignments`)).data.data,
  assignment: async (id: number) => (await api.get<{ data: Assignment }>(`/assignments/${id}`)).data.data,
}

export const useCourses = () => useQuery({ queryKey: courseKeys.all, queryFn: () => loaders.courses() })
export const useCourse = (id: number) => useQuery({ queryKey: courseKeys.detail(id), queryFn: () => loaders.course(id), enabled: id > 0 })
export const useMaterials = (id: number) => useQuery({ queryKey: courseKeys.materials(id), queryFn: () => loaders.materials(id) })
export const useAnnouncements = (id: number) =>
  useQuery({ queryKey: courseKeys.announcements(id), queryFn: () => loaders.announcements(id) })
export const useAssignments = (id: number) => useQuery({ queryKey: courseKeys.assignments(id), queryFn: () => loaders.assignments(id) })
export const useAssignment = (id: number) => useQuery({ queryKey: courseKeys.assignment(id), queryFn: () => loaders.assignment(id) })

export const usePeople = (id: number) =>
  useQuery({ queryKey: courseKeys.people(id), queryFn: async () => (await api.get<People>(`/courses/${id}/people`)).data, enabled: id > 0 })

export const useSubmissions = (assignmentId: number, enabled = true) =>
  useQuery({
    queryKey: courseKeys.submissions(assignmentId),
    enabled,
    queryFn: async () =>
      (await api.get<{ data: SubmissionRow[]; summary: Record<string, number> }>(`/assignments/${assignmentId}/submissions`)).data,
  })
