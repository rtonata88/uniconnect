import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, ChevronLeft, CloudOff, Loader2, Send, Timer, XCircle } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState, ListSkeleton, Page } from '@/components/common'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Forbidden } from '@/features/auth/guards'
import { api, errorMessage } from '@/lib/api'
import { fmtDateTime } from '@/lib/format'
import { useOnline } from '@/lib/useOnline'
import { cn } from '@/lib/utils'
import { quizKeys, useAttempt } from './api'
import type { Answer, Attempt, Question } from './types'
import { formatClock, useCountdown } from './useCountdown'

type Draft = Record<number, { option_id?: number | null; text_answer?: string | null }>

const toPayload = (draft: Draft) => Object.entries(draft).map(([qid, a]) => ({ question_id: Number(qid), ...a }))

/** Read-only answers with correctness (result view and lecturer review). */
export function AnswerReview({ attempt, renderGrading }: { attempt: Attempt; renderGrading?: (q: Question, a: Answer | undefined) => ReactNode }) {
  const answers = new Map(attempt.answers?.map((a) => [a.question_id, a]))
  return (
    <ol className="space-y-4">
      {attempt.questions?.map((q, i) => {
        const a = answers.get(q.id!)
        return (
          <li key={q.id} className="space-y-2 rounded-lg border p-3">
            <div className="flex items-start gap-2">
              <p className="flex-1 text-sm font-medium whitespace-pre-wrap">
                {i + 1}. {q.prompt}
              </p>
              {a?.is_correct === true && <CheckCircle2 className="size-5 text-emerald-600" aria-label="correct" />}
              {a?.is_correct === false && <XCircle className="size-5 text-destructive" aria-label="incorrect" />}
              <Badge variant="outline">{a?.points_awarded ?? '–'} / {q.points}</Badge>
            </div>
            {q.type === 'short_answer' ? (
              <p className="rounded bg-muted/50 p-2 text-sm whitespace-pre-wrap">{a?.text_answer || <em className="text-muted-foreground">No answer</em>}</p>
            ) : (
              <ul className="space-y-1">
                {q.options.map((o) => (
                  <li
                    key={o.id}
                    className={cn(
                      'rounded px-2 py-1 text-sm',
                      a?.option_id === o.id && 'bg-muted font-medium',
                      o.is_correct && 'text-emerald-700 dark:text-emerald-400',
                    )}
                  >
                    {a?.option_id === o.id ? '● ' : '○ '}
                    {o.text}
                    {o.is_correct && ' ✓'}
                  </li>
                ))}
              </ul>
            )}
            {q.type === 'short_answer' && q.correct_text && <p className="text-xs text-muted-foreground">Expected: {q.correct_text}</p>}
            {renderGrading?.(q, a)}
          </li>
        )
      })}
    </ol>
  )
}

function TakeQuiz({ attempt }: { attempt: Attempt }) {
  const qc = useQueryClient()
  const online = useOnline()
  const [draft, setDraft] = useState<Draft>(() =>
    Object.fromEntries((attempt.answers ?? []).map((a) => [a.question_id, { option_id: a.option_id, text_answer: a.text_answer }])),
  )
  const [savedAt, setSavedAt] = useState<string | null>(null)
  const dirty = useRef(false)
  const draftRef = useRef(draft)
  draftRef.current = draft

  const submit = useMutation({
    mutationFn: async (auto: boolean) => {
      const res = await api.post<{ data: Attempt }>(`/attempts/${attempt.id}/submit`, { answers: toPayload(draftRef.current) })
      return { attempt: res.data.data, auto }
    },
    onSuccess: ({ attempt: done, auto }) => {
      qc.setQueryData(quizKeys.attempt(attempt.id), done)
      void qc.invalidateQueries({ queryKey: quizKeys.detail(attempt.quiz_id) })
      toast.success(auto ? 'Time is up — your answers were submitted.' : 'Quiz submitted')
    },
    onError: (e) => toast.error(errorMessage(e, 'Could not submit. Check your connection and try again.')),
  })

  // autosave changed answers every 3 s
  const autosave = useCallback(async () => {
    if (!dirty.current || !online) return
    dirty.current = false
    try {
      const { data } = await api.put<{ saved_at: string }>(`/attempts/${attempt.id}/answers`, { answers: toPayload(draftRef.current) })
      setSavedAt(data.saved_at)
    } catch {
      dirty.current = true
    }
  }, [attempt.id, online])
  useEffect(() => {
    const t = setInterval(autosave, 3000)
    return () => clearInterval(t)
  }, [autosave])

  const left = useCountdown(attempt.deadline_at, attempt.server_now, () => {
    if (!submit.isPending) submit.mutate(true)
  })

  const answer = (qid: number, value: Draft[number]) => {
    dirty.current = true
    setDraft((d) => ({ ...d, [qid]: value }))
  }
  const answered = Object.values(draft).filter((a) => a.option_id || a.text_answer?.trim()).length
  const total = attempt.questions?.length ?? 0
  const urgent = left !== null && left < 60_000

  return (
    <div className="space-y-4">
      <div className="sticky top-14 z-20 -mx-4 flex items-center gap-3 border-b bg-background/95 px-4 py-2 backdrop-blur md:mx-0 md:rounded-lg md:border">
        <Timer className={cn('size-5', urgent && 'text-destructive')} />
        <span className={cn('font-mono text-lg tabular-nums', urgent && 'text-destructive')} role="timer" aria-live="off">
          {left === null ? 'No time limit' : formatClock(left)}
        </span>
        <span className="flex-1 text-xs text-muted-foreground">
          {answered}/{total} answered{savedAt && ` · saved ${new Date(savedAt).toLocaleTimeString()}`}
        </span>
        <Button onClick={() => submit.mutate(false)} disabled={submit.isPending || !online}>
          {submit.isPending ? <Loader2 className="animate-spin" /> : <Send />} Submit
        </Button>
      </div>
      {!online && (
        <Alert variant="destructive">
          <CloudOff />
          <AlertDescription>
            You are offline. Quizzes need a connection — your answers are kept on this screen and will be saved when you reconnect. The timer keeps running.
          </AlertDescription>
        </Alert>
      )}
      <ol className="space-y-4">
        {attempt.questions?.map((q, i) => (
          <li key={q.id}>
            <Card>
              <CardHeader>
                <CardTitle className="text-base font-medium whitespace-pre-wrap">
                  {i + 1}. {q.prompt}
                </CardTitle>
                <CardDescription>{q.points} {q.points === 1 ? 'point' : 'points'}</CardDescription>
              </CardHeader>
              <CardContent>
                {q.type === 'short_answer' ? (
                  <Input
                    aria-label={`Answer to question ${i + 1}`}
                    value={draft[q.id!]?.text_answer ?? ''}
                    onChange={(e) => answer(q.id!, { text_answer: e.target.value })}
                    maxLength={2000}
                  />
                ) : (
                  <RadioGroup
                    value={draft[q.id!]?.option_id ? String(draft[q.id!]?.option_id) : ''}
                    onValueChange={(v) => answer(q.id!, { option_id: Number(v) })}
                    aria-label={`Question ${i + 1}`}
                  >
                    {q.options.map((o) => (
                      <label key={o.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border px-3 has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5">
                        <RadioGroupItem value={String(o.id)} />
                        <span className="text-sm">{o.text}</span>
                      </label>
                    ))}
                  </RadioGroup>
                )}
              </CardContent>
            </Card>
          </li>
        ))}
      </ol>
    </div>
  )
}

function Result({ attempt }: { attempt: Attempt }) {
  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Submitted</CardTitle>
          <CardDescription>
            {attempt.submitted_at && fmtDateTime(attempt.submitted_at)}
            {attempt.auto_submitted && ' · submitted automatically when time ran out'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {attempt.grade_released && attempt.score !== null ? (
            <p className="text-2xl font-semibold">
              {attempt.score} / {attempt.max_score}
            </p>
          ) : (
            <p className="text-sm text-muted-foreground">Your lecturer will release the results. You'll get a notification.</p>
          )}
          {attempt.needs_manual_grading && attempt.grade_released && <p className="mt-1 text-sm text-muted-foreground">Some answers are still being marked.</p>}
        </CardContent>
      </Card>
      {attempt.grade_released && <AnswerReview attempt={attempt} />}
    </div>
  )
}

export function QuizTakePage() {
  const id = Number(useParams().attemptId)
  const attempt = useAttempt(id)
  if (attempt.isPending) return <Page><ListSkeleton /></Page>
  if (attempt.isError) {
    const status = (attempt.error as { response?: { status?: number } }).response?.status
    if (status === 403 || status === 404) return <Forbidden message="This attempt is not available to you." />
    return <Page><ErrorState error={attempt.error} onRetry={() => attempt.refetch()} /></Page>
  }
  return (
    <Page>
      <Link to={`/quizzes/${attempt.data.quiz_id}`} className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
        <ChevronLeft className="size-4" /> Quiz
      </Link>
      {attempt.data.submitted_at ? <Result attempt={attempt.data} /> : <TakeQuiz attempt={attempt.data} />}
    </Page>
  )
}
