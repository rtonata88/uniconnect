import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'
import type { Attempt, Quiz } from './types'

export const quizKeys = {
  list: (courseId: number) => ['courses', courseId, 'quizzes'] as const,
  detail: (id: number) => ['quizzes', id] as const,
  attempts: (id: number) => ['quizzes', id, 'attempts'] as const,
  attempt: (id: number) => ['attempts', id] as const,
}

export const useQuizzes = (courseId: number) =>
  useQuery({ queryKey: quizKeys.list(courseId), queryFn: async () => (await api.get<{ data: Quiz[] }>(`/courses/${courseId}/quizzes`)).data.data })

export const useQuiz = (id: number) =>
  useQuery({ queryKey: quizKeys.detail(id), enabled: id > 0, queryFn: async () => (await api.get<{ data: Quiz }>(`/quizzes/${id}`)).data.data })

export const useQuizAttempts = (id: number, enabled: boolean) =>
  useQuery({ queryKey: quizKeys.attempts(id), enabled, queryFn: async () => (await api.get<{ data: Attempt[] }>(`/quizzes/${id}/attempts`)).data.data })

export const useAttempt = (id: number) =>
  useQuery({ queryKey: quizKeys.attempt(id), enabled: id > 0, queryFn: async () => (await api.get<{ data: Attempt }>(`/attempts/${id}`)).data.data, staleTime: 0 })
