import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import { repo } from '@/lib/repo'
import type { People, SubmissionRow } from './types'

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

/** Read path (PRD 9.5): the repository fetches online and falls back to IndexedDB offline. */
export const loaders = {
  courses: () => repo.getCourses(),
  course: (id: number) => repo.getCourse(id),
  materials: (id: number) => repo.getMaterials(id),
  announcements: (id: number) => repo.getAnnouncements(id),
  assignments: (id: number) => repo.getAssignments(id),
  assignment: (id: number) => repo.getAssignment(id),
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
