import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { Camera, ChevronLeft, Clock, Loader2, Paperclip, Pencil, Send, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { DataTable } from '@/components/DataTable'
import { ErrorState, ListSkeleton, Page, UserAvatar } from '@/components/common'
import { FileCard } from '@/components/FileCard'
import { ShareButton } from '@/components/ShareButton'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Textarea } from '@/components/ui/textarea'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Forbidden } from '@/features/auth/guards'
import { courseKeys, useAssignment, useCourse, useSubmissions } from '@/features/courses/api'
import { AssignmentDialog, AssignmentStatusBadge } from '@/features/courses/tabs/AssignmentsTab'
import type { Assignment, SubmissionRow, SubmissionStatus } from '@/features/courses/types'
import { useIsMobile } from '@/hooks/use-mobile'
import { api, errorMessage } from '@/lib/api'
import { formatBytes, MAX_UPLOAD_BYTES } from '@/lib/files'
import { dueLabel, fmtDateTime } from '@/lib/format'
import { submissionWriter } from './submissionWriter'

function SubmitPanel({ assignment }: { assignment: Assignment }) {
  const qc = useQueryClient()
  const sub = assignment.my_submission
  const [editing, setEditing] = useState(!sub)
  const [text, setText] = useState(sub?.text ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [progress, setProgress] = useState<number | null>(null)
  const pastDue = new Date(assignment.due_at) < new Date()
  const closed = pastDue && !assignment.allow_late

  const submit = useMutation({
    mutationFn: () =>
      submissionWriter.submit({ assignmentId: assignment.id, courseId: assignment.course_id, text, file, onProgress: setProgress }),
    onSuccess: (res) => {
      toast.success(res === 'queued' ? 'You are offline — the submission will be sent automatically.' : 'Submitted')
      setEditing(false)
      setFile(null)
      void qc.invalidateQueries({ queryKey: courseKeys.assignment(assignment.id) })
      void qc.invalidateQueries({ queryKey: courseKeys.assignments(assignment.course_id) })
    },
    onError: (e) => toast.error(errorMessage(e)),
    onSettled: () => setProgress(null),
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          Your submission <AssignmentStatusBadge assignment={assignment} />
        </CardTitle>
        {sub && !sub.pending && (
          <CardDescription>
            Submitted {fmtDateTime(sub.submitted_at)}
            {sub.attempt > 1 && ` · attempt ${sub.attempt}`}
            {sub.is_late && ' · late'}
          </CardDescription>
        )}
      </CardHeader>
      <CardContent className="space-y-4">
        {sub?.grade_released && sub.score !== null && (
          <Alert>
            <AlertDescription className="space-y-1">
              <p className="font-medium">
                Grade: {sub.final_score} / {assignment.max_score}
                {sub.is_late && sub.final_score !== sub.score && ` (raw ${sub.score}, late penalty ${assignment.late_penalty_percent}%)`}
              </p>
              {sub.feedback && <p className="whitespace-pre-wrap">{sub.feedback}</p>}
            </AlertDescription>
          </Alert>
        )}
        {sub && !editing && (
          <>
            {sub.text && <p className="rounded-md bg-muted/50 p-3 text-sm whitespace-pre-wrap">{sub.text}</p>}
            {sub.attachment && <FileCard attachment={sub.attachment} />}
            {sub.pending && (
              <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Clock className="size-4" /> Waiting for a connection to send.
              </p>
            )}
            {!closed && !sub.pending && (
              <Button variant="outline" onClick={() => setEditing(true)}>
                <Pencil /> Resubmit
              </Button>
            )}
          </>
        )}
        {editing && closed && <p className="text-sm text-destructive">The deadline has passed and late submissions are not accepted.</p>}
        {editing && !closed && (
          <FieldGroup>
            {pastDue && (
              <Alert variant="destructive">
                <AlertDescription>
                  The deadline has passed. Late work is accepted with a {assignment.late_penalty_percent}% penalty.
                </AlertDescription>
              </Alert>
            )}
            <Field>
              <FieldLabel htmlFor="sub-text">Answer</FieldLabel>
              <Textarea id="sub-text" rows={6} value={text} onChange={(e) => setText(e.target.value)} placeholder="Type your answer or attach a file" />
            </Field>
            <Field>
              <FieldLabel>File</FieldLabel>
              {file ? (
                <div className="flex items-center gap-2 rounded-md border p-2 text-sm">
                  <Paperclip className="size-4" />
                  <span className="min-w-0 flex-1 truncate">{file.name}</span>
                  <span className="text-muted-foreground">{formatBytes(file.size)}</span>
                  <Button variant="ghost" size="icon" className="size-9" aria-label="Remove file" onClick={() => setFile(null)}>
                    <X />
                  </Button>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  <Button variant="outline" asChild>
                    <label className="cursor-pointer">
                      <Paperclip /> Choose file
                      <input type="file" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                    </label>
                  </Button>
                  {/* MOB-3: phones open the camera directly */}
                  <Button variant="outline" asChild>
                    <label className="cursor-pointer">
                      <Camera /> Take photo
                      <input type="file" accept="image/*" capture="environment" className="sr-only" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
                    </label>
                  </Button>
                </div>
              )}
              {file && file.size > MAX_UPLOAD_BYTES && <p className="text-sm text-destructive">Files may be at most 20 MB.</p>}
              {progress !== null && <Progress value={progress} className="h-1.5" />}
            </Field>
            <div className="flex gap-2">
              <Button
                size="lg"
                onClick={() => submit.mutate()}
                disabled={submit.isPending || (!text.trim() && !file) || (!!file && file.size > MAX_UPLOAD_BYTES)}
              >
                {submit.isPending ? <Loader2 className="animate-spin" /> : <Send />} Submit
              </Button>
              {sub && (
                <Button size="lg" variant="ghost" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
              )}
            </div>
          </FieldGroup>
        )}
      </CardContent>
    </Card>
  )
}

const statusBadge: Record<SubmissionStatus, React.ReactNode> = {
  on_time: <Badge variant="secondary">On time</Badge>,
  late: <Badge className="bg-amber-500/15 text-amber-700 dark:text-amber-400">Late</Badge>,
  missing: <Badge variant="destructive">Missing</Badge>,
  not_submitted: <Badge variant="outline">Not yet</Badge>,
}

function GradingSheet({ assignment, row, onClose }: { assignment: Assignment; row: SubmissionRow | null; onClose: () => void }) {
  const qc = useQueryClient()
  const isMobile = useIsMobile()
  const sub = row?.submission
  const [score, setScore] = useState(sub?.score?.toString() ?? '')
  const [feedback, setFeedback] = useState(sub?.feedback ?? '')
  const save = useMutation({
    mutationFn: () => api.patch(`/submissions/${sub!.id}/grade`, { score: Number(score), feedback: feedback || null }),
    onSuccess: () => {
      toast.success('Grade saved')
      void qc.invalidateQueries({ queryKey: courseKeys.submissions(assignment.id) })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Sheet open={!!row} onOpenChange={(o) => !o && onClose()}>
      <SheetContent side={isMobile ? 'bottom' : 'right'} className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>{row?.student.name}</SheetTitle>
          <SheetDescription>
            {sub ? `Submitted ${fmtDateTime(sub.submitted_at)}${sub.is_late ? ' (late)' : ''} · attempt ${sub.attempt}` : 'No submission'}
          </SheetDescription>
        </SheetHeader>
        {sub && (
          <div className="space-y-4 px-4">
            {sub.text && <p className="rounded-md bg-muted/50 p-3 text-sm whitespace-pre-wrap">{sub.text}</p>}
            {sub.attachment && <FileCard attachment={sub.attachment} />}
            <FieldGroup>
              <Field>
                <FieldLabel htmlFor="g-score">Score (out of {assignment.max_score})</FieldLabel>
                <Input id="g-score" type="number" min={0} max={assignment.max_score} step="0.5" value={score} onChange={(e) => setScore(e.target.value)} className="w-32" />
                {sub.is_late && assignment.late_penalty_percent > 0 && (
                  <p className="text-xs text-muted-foreground">A {assignment.late_penalty_percent}% late penalty is applied to the final score.</p>
                )}
              </Field>
              <Field>
                <FieldLabel htmlFor="g-fb">Feedback</FieldLabel>
                <Textarea id="g-fb" rows={5} value={feedback} onChange={(e) => setFeedback(e.target.value)} />
              </Field>
            </FieldGroup>
          </div>
        )}
        <SheetFooter>
          {sub && (
            <Button onClick={() => save.mutate()} disabled={save.isPending || score === ''}>
              {save.isPending && <Loader2 className="animate-spin" />} Save grade
            </Button>
          )}
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

function SubmissionsPanel({ assignment }: { assignment: Assignment }) {
  const subs = useSubmissions(assignment.id)
  const [filter, setFilter] = useState<'all' | SubmissionStatus>('all')
  const [grading, setGrading] = useState<SubmissionRow | null>(null)

  const columns = useMemo<ColumnDef<SubmissionRow, unknown>[]>(
    () => [
      {
        id: 'student',
        header: 'Student',
        accessorFn: (r) => r.student.name,
        cell: ({ row }) => (
          <div className="flex items-center gap-2">
            <UserAvatar user={row.original.student} className="size-8" />
            <div>
              <p className="font-medium">{row.original.student.name}</p>
              <p className="text-xs text-muted-foreground">{row.original.student.student_number}</p>
            </div>
          </div>
        ),
      },
      { id: 'status', header: 'Status', accessorFn: (r) => r.status, cell: ({ row }) => statusBadge[row.original.status] },
      {
        id: 'submitted',
        header: 'Submitted',
        accessorFn: (r) => r.submission?.submitted_at ?? '',
        cell: ({ row }) => (row.original.submission ? fmtDateTime(row.original.submission.submitted_at) : '—'),
      },
      {
        id: 'score',
        header: 'Score',
        accessorFn: (r) => r.submission?.final_score ?? -1,
        cell: ({ row }) => {
          const s = row.original.submission
          if (!s) return '—'
          return s.is_graded ? `${s.final_score} / ${assignment.max_score}` : <Badge variant="outline">To grade</Badge>
        },
      },
    ],
    [assignment.max_score],
  )

  if (subs.isPending) return <ListSkeleton />
  if (subs.isError) return <ErrorState error={subs.error} onRetry={() => subs.refetch()} />
  const rows = filter === 'all' ? subs.data.data : subs.data.data.filter((r) => r.status === filter)
  const s = subs.data.summary

  return (
    <Card>
      <CardHeader>
        <CardTitle>Submissions</CardTitle>
        <CardDescription>
          {s.on_time} on time · {s.late} late · {s.missing + s.not_submitted} not submitted · {s.graded} graded
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <ToggleGroup type="single" variant="outline" value={filter} onValueChange={(v) => v && setFilter(v as typeof filter)} className="flex-wrap">
          <ToggleGroupItem value="all">All</ToggleGroupItem>
          <ToggleGroupItem value="on_time">On time</ToggleGroupItem>
          <ToggleGroupItem value="late">Late</ToggleGroupItem>
          <ToggleGroupItem value="missing">Missing</ToggleGroupItem>
        </ToggleGroup>
        <DataTable columns={columns} data={rows} onRowClick={(r) => r.submission && setGrading(r)} empty="No students in this view." />
      </CardContent>
      <GradingSheet key={grading?.student.id ?? 'none'} assignment={assignment} row={grading} onClose={() => setGrading(null)} />
    </Card>
  )
}

export function AssignmentPage() {
  const id = Number(useParams().assignmentId)
  const assignment = useAssignment(id)
  const course = useCourse(assignment.data?.course_id ?? 0)
  const [editing, setEditing] = useState(false)

  if (assignment.isPending) return <Page><ListSkeleton rows={3} /></Page>
  if (assignment.isError) {
    const status = (assignment.error as { response?: { status?: number } }).response?.status
    if (status === 403 || status === 404) return <Forbidden message="This assignment is not available to you." />
    return <Page><ErrorState error={assignment.error} onRetry={() => assignment.refetch()} /></Page>
  }
  const a = assignment.data
  const isLecturer = course.data?.my_role === 'lecturer'

  return (
    <Page>
      <Link to={`/courses/${a.course_id}/assignments`} className="inline-flex min-h-11 items-center gap-1 text-sm text-muted-foreground hover:text-foreground md:min-h-0">
        <ChevronLeft className="size-4" /> {course.data ? `${course.data.code} assignments` : 'Back'}
      </Link>
      <Card>
        <CardHeader className="flex flex-row items-start gap-3">
          <div className="min-w-0 flex-1">
            <CardTitle className="text-xl">
              <h1>{a.title}</h1>
            </CardTitle>
            <CardDescription className="mt-1 flex flex-wrap gap-x-3 gap-y-1">
              <span>{dueLabel(a.due_at)} ({fmtDateTime(a.due_at)})</span>
              <span>{a.max_score} points</span>
              <span>{a.allow_late ? `Late accepted (−${a.late_penalty_percent}%)` : 'No late submissions'}</span>
            </CardDescription>
          </div>
          <div className="flex gap-2">
            <ShareButton title={a.title} text={`Due ${fmtDateTime(a.due_at)}`} path={`/assignments/${a.id}`} />
            {isLecturer && (
              <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                <Pencil /> Edit
              </Button>
            )}
          </div>
        </CardHeader>
        <CardContent className="space-y-3">
          {a.instructions && <p className="text-sm whitespace-pre-wrap">{a.instructions}</p>}
          {a.attachment && <FileCard attachment={a.attachment} />}
        </CardContent>
      </Card>
      {isLecturer ? <SubmissionsPanel assignment={a} /> : course.data && <SubmitPanel key={a.my_submission?.id ?? 'new'} assignment={a} />}
      {editing && <AssignmentDialog courseId={a.course_id} assignment={a} onClose={() => setEditing(false)} />}
    </Page>
  )
}
