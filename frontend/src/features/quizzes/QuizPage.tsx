import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { ChevronLeft, Loader2, Pencil, Play, WifiOff } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { DataTable } from '@/components/DataTable'
import { ErrorState, ListSkeleton, Page } from '@/components/common'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Forbidden } from '@/features/auth/guards'
import { useCourse } from '@/features/courses/api'
import { useIsMobile } from '@/hooks/use-mobile'
import { useOnline } from '@/lib/useOnline'
import { api, errorMessage } from '@/lib/api'
import { fmtDateTime } from '@/lib/format'
import { quizKeys, useAttempt, useQuiz, useQuizAttempts } from './api'
import { quizWindow } from './QuizzesTab'
import { AnswerReview } from './QuizTakePage'
import type { Attempt, Quiz } from './types'

function ManualGradeSheet({ attemptId, onClose }: { attemptId: number | null; onClose: () => void }) {
  const qc = useQueryClient()
  const isMobile = useIsMobile()
  const attempt = useAttempt(attemptId ?? 0)
  const [points, setPoints] = useState<Record<number, string>>({})
  const save = useMutation({
    mutationFn: () => api.patch(`/attempts/${attemptId}/grade`, { points: Object.fromEntries(Object.entries(points).map(([k, v]) => [k, Number(v)])) }),
    onSuccess: (res) => {
      toast.success('Grades saved')
      qc.setQueryData(quizKeys.attempt(attemptId!), res.data.data)
      void qc.invalidateQueries({ queryKey: quizKeys.attempts(res.data.data.quiz_id) })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Sheet open={attemptId !== null} onOpenChange={(o) => !o && onClose()}>
      <SheetContent side={isMobile ? 'bottom' : 'right'} className="max-h-[90svh] overflow-y-auto sm:max-w-xl">
        <SheetHeader>
          <SheetTitle>{attempt.data?.student?.name}</SheetTitle>
          <SheetDescription>
            {attempt.data?.submitted_at ? `Submitted ${fmtDateTime(attempt.data.submitted_at)}${attempt.data.auto_submitted ? ' (time ran out)' : ''}` : 'In progress'}
            {attempt.data?.score !== null && attempt.data && ` · ${attempt.data.score} / ${attempt.data.max_score}`}
          </SheetDescription>
        </SheetHeader>
        <div className="px-4">
          {attempt.data ? (
            <AnswerReview
              attempt={attempt.data}
              renderGrading={(q, answer) =>
                q.type === 'short_answer' && attempt.data.submitted_at ? (
                  <div className="flex items-center gap-2 text-sm">
                    <label htmlFor={`pts-${q.id}`}>Points</label>
                    <Input
                      id={`pts-${q.id}`}
                      type="number"
                      min={0}
                      max={q.points}
                      step="0.5"
                      className="h-9 w-24"
                      value={points[q.id!] ?? answer?.points_awarded?.toString() ?? ''}
                      onChange={(e) => setPoints((p) => ({ ...p, [q.id!]: e.target.value }))}
                    />
                    <span className="text-muted-foreground">/ {q.points}</span>
                  </div>
                ) : null
              }
            />
          ) : (
            <ListSkeleton />
          )}
        </div>
        <SheetFooter>
          <Button disabled={!Object.keys(points).length || save.isPending} onClick={() => save.mutate()}>
            {save.isPending && <Loader2 className="animate-spin" />} Save points
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

function LecturerView({ quiz }: { quiz: Quiz }) {
  const attempts = useQuizAttempts(quiz.id, true)
  const [grading, setGrading] = useState<number | null>(null)
  const columns = useMemo<ColumnDef<Attempt, unknown>[]>(
    () => [
      { id: 'student', header: 'Student', accessorFn: (a) => a.student?.name ?? '' },
      { id: 'started', header: 'Started', accessorFn: (a) => a.started_at, cell: ({ row }) => fmtDateTime(row.original.started_at) },
      {
        id: 'status',
        header: 'Status',
        accessorFn: (a) => (a.submitted_at ? (a.needs_manual_grading ? 1 : 2) : 0),
        cell: ({ row }) => {
          const a = row.original
          if (!a.submitted_at) return <Badge variant="outline">In progress</Badge>
          if (a.needs_manual_grading) return <Badge className="bg-amber-500/15 text-amber-700 dark:text-amber-400">Needs grading</Badge>
          return <Badge variant="secondary">{a.auto_submitted ? 'Auto-submitted' : 'Submitted'}</Badge>
        },
      },
      { id: 'score', header: 'Score', accessorFn: (a) => a.score ?? -1, cell: ({ row }) => (row.original.score !== null ? `${row.original.score} / ${row.original.max_score}` : '—') },
    ],
    [],
  )
  return (
    <Card>
      <CardHeader>
        <CardTitle>Attempts</CardTitle>
        <CardDescription>Open an attempt to review answers and mark short answers.</CardDescription>
      </CardHeader>
      <CardContent>
        {attempts.isPending ? <ListSkeleton /> : attempts.isError ? <ErrorState error={attempts.error} /> : (
          <DataTable columns={columns} data={attempts.data} onRowClick={(a) => setGrading(a.id)} empty="Nobody has taken this quiz yet." />
        )}
      </CardContent>
      <ManualGradeSheet key={grading ?? 'none'} attemptId={grading} onClose={() => setGrading(null)} />
    </Card>
  )
}

function StudentView({ quiz }: { quiz: Quiz }) {
  const online = useOnline()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const attempts = quiz.my_attempts ?? []
  const inProgress = attempts.find((a) => !a.submitted_at)
  const used = attempts.length
  const start = useMutation({
    mutationFn: async () => (await api.post<{ data: Attempt }>(`/quizzes/${quiz.id}/attempts`)).data.data,
    onSuccess: (a) => {
      qc.setQueryData(quizKeys.attempt(a.id), a)
      navigate(`/attempts/${a.id}`)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const canStart = quiz.is_open && (inProgress || used < quiz.max_attempts)

  return (
    <Card>
      <CardHeader>
        <CardTitle>Your attempts</CardTitle>
        <CardDescription>
          {used} of {quiz.max_attempts} used
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {!online && (
          <Alert>
            <WifiOff />
            <AlertDescription>Quizzes need a connection. Connect to the internet to start or continue this quiz.</AlertDescription>
          </Alert>
        )}
        {canStart && (
          <Button size="lg" disabled={!online || start.isPending} onClick={() => start.mutate()}>
            {start.isPending ? <Loader2 className="animate-spin" /> : <Play />} {inProgress ? 'Continue attempt' : 'Start quiz'}
          </Button>
        )}
        {!quiz.is_open && <p className="text-sm text-muted-foreground">This quiz is not open right now. {quizWindow(quiz)}</p>}
        <ul className="divide-y">
          {attempts.map((a, i) => (
            <li key={a.id} className="flex min-h-12 items-center gap-3">
              <span className="text-sm">Attempt {attempts.length - i}</span>
              <span className="flex-1 text-xs text-muted-foreground">{a.submitted_at ? `Submitted ${fmtDateTime(a.submitted_at)}` : 'In progress'}</span>
              {a.submitted_at && (a.grade_released && a.score !== null ? <Badge>{a.score} / {a.max_score}</Badge> : <Badge variant="secondary">Awaiting release</Badge>)}
              {a.submitted_at && (
                <Button variant="ghost" size="sm" asChild>
                  <Link to={`/attempts/${a.id}`}>View</Link>
                </Button>
              )}
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}

export function QuizPage() {
  const id = Number(useParams().quizId)
  const quiz = useQuiz(id)
  const course = useCourse(quiz.data?.course_id ?? 0)
  if (quiz.isPending) return <Page><ListSkeleton /></Page>
  if (quiz.isError) {
    const status = (quiz.error as { response?: { status?: number } }).response?.status
    if (status === 403 || status === 404) return <Forbidden message="This quiz is not available to you." />
    return <Page><ErrorState error={quiz.error} onRetry={() => quiz.refetch()} /></Page>
  }
  const q = quiz.data
  const isLecturer = course.data?.my_role === 'lecturer'
  return (
    <Page>
      <Link to={`/courses/${q.course_id}/quizzes`} className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
        <ChevronLeft className="size-4" /> {course.data ? `${course.data.code} quizzes` : 'Back'}
      </Link>
      <Card>
        <CardHeader className="flex flex-row items-start gap-3">
          <div className="min-w-0 flex-1">
            <CardTitle className="text-xl">{q.title}</CardTitle>
            <CardDescription className="mt-1">
              {q.question_count} questions · {q.max_score} points · {q.time_limit_minutes ? `${q.time_limit_minutes} minutes` : 'no time limit'} · {quizWindow(q)}
            </CardDescription>
          </div>
          {isLecturer && (
            <Button variant="outline" size="sm" asChild>
              <Link to={`/quizzes/${q.id}/edit`}>
                <Pencil /> Edit
              </Link>
            </Button>
          )}
        </CardHeader>
        {q.instructions && (
          <CardContent>
            <p className="text-sm whitespace-pre-wrap">{q.instructions}</p>
          </CardContent>
        )}
      </Card>
      {course.data && (isLecturer ? <LecturerView quiz={q} /> : <StudentView quiz={q} />)}
    </Page>
  )
}
