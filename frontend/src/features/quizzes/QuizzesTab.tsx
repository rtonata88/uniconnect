import { Plus, Timer } from 'lucide-react'
import { Link } from 'react-router'
import { EmptyState, ErrorState, ListSkeleton } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import type { CourseTabProps } from '@/features/courses/CoursePage'
import { fmtDateTime } from '@/lib/format'
import { useQuizzes } from './api'
import type { Quiz } from './types'

export function quizWindow(q: Quiz) {
  const parts = []
  if (q.opens_at) parts.push(`Opens ${fmtDateTime(q.opens_at)}`)
  if (q.closes_at) parts.push(`Closes ${fmtDateTime(q.closes_at)}`)
  return parts.join(' · ')
}

function StudentStatus({ quiz }: { quiz: Quiz }) {
  const attempts = quiz.my_attempts ?? []
  const done = attempts.filter((a) => a.submitted_at)
  if (attempts.some((a) => !a.submitted_at)) return <Badge>In progress</Badge>
  if (done.length && quiz.grade_released) {
    const best = Math.max(...done.map((a) => a.score ?? 0))
    return <Badge>{best} / {quiz.max_score}</Badge>
  }
  if (done.length) return <Badge variant="secondary">Submitted</Badge>
  return quiz.is_open ? <Badge variant="outline">Open</Badge> : <Badge variant="outline">Closed</Badge>
}

export function QuizzesTab({ course, isLecturer }: CourseTabProps) {
  const quizzes = useQuizzes(course.id)
  if (quizzes.isPending) return <ListSkeleton />
  if (quizzes.isError) return <ErrorState error={quizzes.error} onRetry={() => quizzes.refetch()} />
  return (
    <div className="space-y-3">
      {isLecturer && (
        <Button asChild>
          <Link to={`/courses/${course.id}/quizzes/new`}>
            <Plus /> New quiz
          </Link>
        </Button>
      )}
      {quizzes.data.length === 0 && <EmptyState icon={Timer} title="No quizzes" hint="Quizzes from your lecturers will show up here." />}
      {quizzes.data.map((q) => (
        <Link key={q.id} to={`/quizzes/${q.id}`} className="flex min-h-16 items-center gap-3 rounded-lg border p-3 hover:bg-muted/40">
          <Timer className="size-5 shrink-0 text-muted-foreground" />
          <div className="min-w-0 flex-1">
            <p className="truncate font-medium">{q.title}</p>
            <p className="text-xs text-muted-foreground">
              {q.question_count} questions · {q.time_limit_minutes ? `${q.time_limit_minutes} min` : 'no time limit'} · {quizWindow(q)}
            </p>
          </div>
          {isLecturer ? (
            <div className="flex gap-2">
              {!q.published_at && <Badge variant="outline">Draft</Badge>}
              <Badge variant="secondary">{q.attempts_count ?? 0} attempts</Badge>
            </div>
          ) : (
            <StudentStatus quiz={q} />
          )}
        </Link>
      ))}
    </div>
  )
}
