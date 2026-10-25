import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowDown, ArrowUp, CheckCircle2, ChevronLeft, Circle, Loader2, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState, ListSkeleton, Page, PageHeader } from '@/components/common'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { api, errorMessage, fieldErrors } from '@/lib/api'
import { toLocalInput } from '@/lib/format'
import { quizKeys, useQuiz } from './api'
import type { Question, QuestionType, Quiz } from './types'

const blank = (type: QuestionType): Question => ({
  type,
  prompt: '',
  points: 1,
  correct_text: type === 'true_false' ? 'true' : '',
  options: type === 'mcq' ? [{ text: '', is_correct: true }, { text: '', is_correct: false }] : [],
})

function QuestionEditor({
  q,
  index,
  count,
  error,
  onChange,
  onMove,
  onRemove,
}: {
  q: Question
  index: number
  count: number
  error?: string
  onChange: (q: Question) => void
  onMove: (dir: -1 | 1) => void
  onRemove: () => void
}) {
  const set = (patch: Partial<Question>) => onChange({ ...q, ...patch })
  return (
    <Card>
      <CardHeader className="flex flex-row items-center gap-2">
        <CardTitle className="flex-1 text-base">Question {index + 1}</CardTitle>
        <Button variant="ghost" size="icon" className="size-9" aria-label="Move up" disabled={index === 0} onClick={() => onMove(-1)}>
          <ArrowUp />
        </Button>
        <Button variant="ghost" size="icon" className="size-9" aria-label="Move down" disabled={index === count - 1} onClick={() => onMove(1)}>
          <ArrowDown />
        </Button>
        <Button variant="ghost" size="icon" className="size-9" aria-label="Remove question" onClick={onRemove}>
          <Trash2 />
        </Button>
      </CardHeader>
      <CardContent>
        <FieldGroup>
          <div className="grid gap-4 sm:grid-cols-[1fr_8rem]">
            <Field>
              <FieldLabel htmlFor={`q${index}-type`}>Type</FieldLabel>
              <Select value={q.type} onValueChange={(v) => onChange({ ...blank(v as QuestionType), prompt: q.prompt, points: q.points })}>
                <SelectTrigger id={`q${index}-type`} className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="mcq">Multiple choice</SelectItem>
                  <SelectItem value="true_false">True / false</SelectItem>
                  <SelectItem value="short_answer">Short answer</SelectItem>
                </SelectContent>
              </Select>
            </Field>
            <Field>
              <FieldLabel htmlFor={`q${index}-pts`}>Points</FieldLabel>
              <Input id={`q${index}-pts`} type="number" min={0} step="0.5" value={q.points} onChange={(e) => set({ points: Number(e.target.value) })} />
            </Field>
          </div>
          <Field>
            <FieldLabel htmlFor={`q${index}-prompt`}>Question</FieldLabel>
            <Textarea id={`q${index}-prompt`} value={q.prompt} onChange={(e) => set({ prompt: e.target.value })} rows={2} />
          </Field>
          {q.type === 'mcq' && (
            <Field>
              <FieldLabel>Options (tap the circle to mark the correct one)</FieldLabel>
              <div className="space-y-2">
                {q.options.map((o, oi) => (
                  <div key={oi} className="flex items-center gap-2">
                    <button
                      type="button"
                      aria-label={o.is_correct ? 'Correct option' : 'Mark as correct'}
                      className="flex size-11 shrink-0 items-center justify-center"
                      onClick={() => set({ options: q.options.map((x, xi) => ({ ...x, is_correct: xi === oi })) })}
                    >
                      {o.is_correct ? <CheckCircle2 className="size-5 text-emerald-600" /> : <Circle className="size-5 text-muted-foreground" />}
                    </button>
                    <Input
                      aria-label={`Option ${oi + 1}`}
                      value={o.text}
                      onChange={(e) => set({ options: q.options.map((x, xi) => (xi === oi ? { ...x, text: e.target.value } : x)) })}
                    />
                    <Button
                      variant="ghost"
                      size="icon"
                      className="size-9"
                      aria-label="Remove option"
                      disabled={q.options.length <= 2}
                      onClick={() => set({ options: q.options.filter((_, xi) => xi !== oi) })}
                    >
                      <Trash2 />
                    </Button>
                  </div>
                ))}
                {q.options.length < 10 && (
                  <Button variant="outline" size="sm" onClick={() => set({ options: [...q.options, { text: '', is_correct: false }] })}>
                    <Plus /> Add option
                  </Button>
                )}
              </div>
            </Field>
          )}
          {q.type === 'true_false' && (
            <Field>
              <FieldLabel htmlFor={`q${index}-tf`}>Correct answer</FieldLabel>
              <Select value={q.correct_text ?? 'true'} onValueChange={(v) => set({ correct_text: v })}>
                <SelectTrigger id={`q${index}-tf`} className="w-40">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="true">True</SelectItem>
                  <SelectItem value="false">False</SelectItem>
                </SelectContent>
              </Select>
            </Field>
          )}
          {q.type === 'short_answer' && (
            <Field>
              <FieldLabel htmlFor={`q${index}-key`}>Expected answer (optional)</FieldLabel>
              <Input id={`q${index}-key`} value={q.correct_text ?? ''} onChange={(e) => set({ correct_text: e.target.value })} />
              <FieldDescription>If set, answers are marked automatically (exact match, case-insensitive). Leave empty to grade by hand.</FieldDescription>
            </Field>
          )}
          {error && <p className="text-sm text-destructive">{error}</p>}
        </FieldGroup>
      </CardContent>
    </Card>
  )
}

/** Turn stored true/false options back into the builder's correct_text form. */
function toBuilder(q: Question): Question {
  if (q.type === 'true_false') {
    const truthy = q.options.find((o) => o.text.toLowerCase() === 'true')?.is_correct ?? true
    return { ...q, correct_text: truthy ? 'true' : 'false', options: [] }
  }
  return { ...q, correct_text: q.correct_text ?? '' }
}

function Builder({ courseId, quiz }: { courseId: number; quiz?: Quiz }) {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const [title, setTitle] = useState(quiz?.title ?? '')
  const [instructions, setInstructions] = useState(quiz?.instructions ?? '')
  const [opens, setOpens] = useState(quiz?.opens_at ? toLocalInput(quiz.opens_at) : toLocalInput(new Date().toISOString()))
  const [closes, setCloses] = useState(quiz?.closes_at ? toLocalInput(quiz.closes_at) : toLocalInput(new Date(Date.now() + 7 * 864e5).toISOString()))
  const [limit, setLimit] = useState(String(quiz?.time_limit_minutes ?? 15))
  const [attempts, setAttempts] = useState(String(quiz?.max_attempts ?? 1))
  const [shuffle, setShuffle] = useState(quiz?.shuffle_questions ?? false)
  const [publish, setPublish] = useState(quiz ? !!quiz.published_at : true)
  const [questions, setQuestions] = useState<Question[]>(quiz?.questions?.map(toBuilder) ?? [blank('mcq')])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const locked = (quiz?.attempts_count ?? 0) > 0

  const save = useMutation({
    mutationFn: async () => {
      const body = {
        title,
        instructions: instructions || null,
        opens_at: opens ? new Date(opens).toISOString() : null,
        closes_at: closes ? new Date(closes).toISOString() : null,
        time_limit_minutes: limit ? Number(limit) : null,
        max_attempts: Number(attempts),
        shuffle_questions: shuffle,
        publish,
        ...(locked
          ? {}
          : {
              questions: questions.map((q) => ({
                type: q.type,
                prompt: q.prompt,
                points: q.points,
                correct_text: q.type === 'mcq' ? null : q.correct_text || null,
                options: q.type === 'mcq' ? q.options.map((o) => ({ text: o.text, is_correct: !!o.is_correct })) : [],
              })),
            }),
      }
      const res = quiz ? await api.patch<{ data: Quiz }>(`/quizzes/${quiz.id}`, body) : await api.post<{ data: Quiz }>(`/courses/${courseId}/quizzes`, body)
      return res.data.data
    },
    onSuccess: (saved) => {
      toast.success('Quiz saved')
      void qc.invalidateQueries({ queryKey: quizKeys.list(courseId) })
      void qc.invalidateQueries({ queryKey: quizKeys.detail(saved.id) })
      navigate(`/quizzes/${saved.id}`)
    },
    onError: (e) => {
      setErrors(fieldErrors(e))
      toast.error(errorMessage(e))
    },
  })

  const total = questions.reduce((s, q) => s + (Number(q.points) || 0), 0)
  const move = (i: number, dir: -1 | 1) =>
    setQuestions((qs) => {
      const copy = [...qs]
      ;[copy[i], copy[i + dir]] = [copy[i + dir], copy[i]]
      return copy
    })

  return (
    <Page>
      <Link to={quiz ? `/quizzes/${quiz.id}` : `/courses/${courseId}/quizzes`} className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
        <ChevronLeft className="size-4" /> Back
      </Link>
      <PageHeader title={quiz ? 'Edit quiz' : 'New quiz'} description={`${questions.length} questions · ${total} points`} />
      <Card>
        <CardContent>
          <FieldGroup>
            <Field>
              <FieldLabel htmlFor="qz-title">Title</FieldLabel>
              <Input id="qz-title" value={title} onChange={(e) => setTitle(e.target.value)} />
              {errors.title && <p className="text-sm text-destructive">{errors.title}</p>}
            </Field>
            <Field>
              <FieldLabel htmlFor="qz-ins">Instructions</FieldLabel>
              <Textarea id="qz-ins" value={instructions} onChange={(e) => setInstructions(e.target.value)} />
            </Field>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field>
                <FieldLabel htmlFor="qz-open">Opens</FieldLabel>
                <Input id="qz-open" type="datetime-local" value={opens} onChange={(e) => setOpens(e.target.value)} />
              </Field>
              <Field>
                <FieldLabel htmlFor="qz-close">Closes</FieldLabel>
                <Input id="qz-close" type="datetime-local" value={closes} onChange={(e) => setCloses(e.target.value)} />
                {errors.closes_at && <p className="text-sm text-destructive">{errors.closes_at}</p>}
              </Field>
              <Field>
                <FieldLabel htmlFor="qz-limit">Time limit (minutes, empty = none)</FieldLabel>
                <Input id="qz-limit" type="number" min={1} value={limit} onChange={(e) => setLimit(e.target.value)} />
              </Field>
              <Field>
                <FieldLabel htmlFor="qz-att">Attempts allowed</FieldLabel>
                <Input id="qz-att" type="number" min={1} max={20} value={attempts} onChange={(e) => setAttempts(e.target.value)} />
              </Field>
            </div>
            <Field orientation="horizontal">
              <Switch id="qz-shuffle" checked={shuffle} onCheckedChange={setShuffle} />
              <FieldLabel htmlFor="qz-shuffle">Shuffle question order for each student</FieldLabel>
            </Field>
            <Field orientation="horizontal">
              <Switch id="qz-pub" checked={publish} onCheckedChange={setPublish} />
              <FieldLabel htmlFor="qz-pub">Visible to students</FieldLabel>
            </Field>
          </FieldGroup>
        </CardContent>
      </Card>
      {locked ? (
        <p className="rounded-md border p-3 text-sm text-muted-foreground">Students have started this quiz, so its questions are locked. You can still change the settings above.</p>
      ) : (
        <>
          {questions.map((q, i) => (
            <QuestionEditor
              key={i}
              q={q}
              index={i}
              count={questions.length}
              error={errors[`questions.${i}.options`] ?? errors[`questions.${i}.prompt`]}
              onChange={(nq) => setQuestions((qs) => qs.map((x, xi) => (xi === i ? nq : x)))}
              onMove={(dir) => move(i, dir)}
              onRemove={() => setQuestions((qs) => qs.filter((_, xi) => xi !== i))}
            />
          ))}
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" onClick={() => setQuestions((qs) => [...qs, blank('mcq')])}>
              <Plus /> Multiple choice
            </Button>
            <Button variant="outline" onClick={() => setQuestions((qs) => [...qs, blank('true_false')])}>
              <Plus /> True / false
            </Button>
            <Button variant="outline" onClick={() => setQuestions((qs) => [...qs, blank('short_answer')])}>
              <Plus /> Short answer
            </Button>
          </div>
        </>
      )}
      <div className="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] flex justify-end border-t bg-background/95 py-3 backdrop-blur md:bottom-0">
        <Button size="lg" onClick={() => save.mutate()} disabled={save.isPending || !title.trim() || (!locked && questions.length === 0)}>
          {save.isPending && <Loader2 className="animate-spin" />} Save quiz
        </Button>
      </div>
    </Page>
  )
}

export function QuizBuilderPage() {
  const { courseId, quizId } = useParams()
  const quiz = useQuiz(Number(quizId ?? 0))
  if (quizId) {
    if (quiz.isPending) return <Page><ListSkeleton /></Page>
    if (quiz.isError) return <Page><ErrorState error={quiz.error} /></Page>
    return <Builder courseId={quiz.data.course_id} quiz={quiz.data} />
  }
  return <Builder courseId={Number(courseId)} />
}
