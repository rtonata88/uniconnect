import { useMutation, useQueryClient } from '@tanstack/react-query'
import { CheckCircle2, ClipboardList, Clock, Loader2, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { EmptyState, ErrorState, ListSkeleton } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { api, errorMessage } from '@/lib/api'
import { uploadFile } from '@/lib/files'
import { dueLabel, fmtDateTime, toLocalInput } from '@/lib/format'
import type { CourseTabProps } from '../CoursePage'
import { courseKeys, useAssignments } from '../api'
import type { Assignment } from '../types'

export function AssignmentDialog({ courseId, assignment, onClose }: { courseId: number; assignment?: Assignment; onClose: () => void }) {
  const qc = useQueryClient()
  const [title, setTitle] = useState(assignment?.title ?? '')
  const [instructions, setInstructions] = useState(assignment?.instructions ?? '')
  const [due, setDue] = useState(assignment ? toLocalInput(assignment.due_at) : toLocalInput(new Date(Date.now() + 7 * 864e5).toISOString()))
  const [maxScore, setMaxScore] = useState(String(assignment?.max_score ?? 100))
  const [allowLate, setAllowLate] = useState(assignment?.allow_late ?? false)
  const [penalty, setPenalty] = useState(String(assignment?.late_penalty_percent ?? 10))
  const [publish, setPublish] = useState(assignment ? !!assignment.published_at : true)
  const [file, setFile] = useState<File | null>(null)
  const [progress, setProgress] = useState<number | null>(null)

  const save = useMutation({
    mutationFn: async () => {
      const attachment_id = file ? (await uploadFile(file, file.name, setProgress)).id : assignment?.attachment?.id ?? null
      const body = {
        title,
        instructions: instructions || null,
        due_at: new Date(due).toISOString(),
        max_score: Number(maxScore),
        allow_late: allowLate,
        late_penalty_percent: allowLate ? Number(penalty) : 0,
        attachment_id,
        publish,
      }
      return assignment
        ? api.patch(`/assignments/${assignment.id}`, { ...body, updated_at: assignment.updated_at })
        : api.post(`/courses/${courseId}/assignments`, body)
    },
    onSuccess: () => {
      toast.success(assignment ? 'Assignment updated' : 'Assignment created')
      void qc.invalidateQueries({ queryKey: courseKeys.assignments(courseId) })
      if (assignment) void qc.invalidateQueries({ queryKey: courseKeys.assignment(assignment.id) })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
    onSettled: () => setProgress(null),
  })

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90svh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{assignment ? 'Edit assignment' : 'New assignment'}</DialogTitle>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="as-title">Title</FieldLabel>
            <Input id="as-title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor="as-instr">Instructions</FieldLabel>
            <Textarea id="as-instr" value={instructions} onChange={(e) => setInstructions(e.target.value)} rows={5} />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field>
              <FieldLabel htmlFor="as-due">Due</FieldLabel>
              <Input id="as-due" type="datetime-local" value={due} onChange={(e) => setDue(e.target.value)} />
            </Field>
            <Field>
              <FieldLabel htmlFor="as-max">Max score</FieldLabel>
              <Input id="as-max" type="number" min={1} value={maxScore} onChange={(e) => setMaxScore(e.target.value)} />
            </Field>
          </div>
          <Field orientation="horizontal">
            <Switch id="as-late" checked={allowLate} onCheckedChange={setAllowLate} />
            <FieldLabel htmlFor="as-late">Accept late submissions</FieldLabel>
          </Field>
          {allowLate && (
            <Field>
              <FieldLabel htmlFor="as-pen">Late penalty (%)</FieldLabel>
              <Input id="as-pen" type="number" min={0} max={100} value={penalty} onChange={(e) => setPenalty(e.target.value)} className="w-28" />
              <FieldDescription>Deducted from the score of late submissions.</FieldDescription>
            </Field>
          )}
          <Field>
            <FieldLabel htmlFor="as-file">Attachment (optional)</FieldLabel>
            <Input id="as-file" type="file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            {progress !== null && <Progress value={progress} className="h-1.5" />}
          </Field>
          <Field orientation="horizontal">
            <Switch id="as-pub" checked={publish} onCheckedChange={setPublish} />
            <FieldLabel htmlFor="as-pub">Visible to students</FieldLabel>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button onClick={() => save.mutate()} disabled={save.isPending || !title.trim() || !due}>
            {save.isPending && <Loader2 className="animate-spin" />} Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function AssignmentStatusBadge({ assignment }: { assignment: Assignment }) {
  const sub = assignment.my_submission
  if (sub?.pending) return <Badge variant="outline"><Clock /> Waiting to send</Badge>
  if (sub?.grade_released && sub.final_score !== null) return <Badge>{sub.final_score} / {assignment.max_score}</Badge>
  if (sub) return <Badge variant="secondary"><CheckCircle2 /> {sub.is_late ? 'Submitted late' : 'Submitted'}</Badge>
  if (new Date(assignment.due_at) < new Date()) return <Badge variant="destructive">Missing</Badge>
  return <Badge variant="outline">To do</Badge>
}

export function AssignmentsTab({ course, isLecturer }: CourseTabProps) {
  const items = useAssignments(course.id)
  const [creating, setCreating] = useState(false)

  if (items.isPending) return <ListSkeleton />
  if (items.isError) return <ErrorState error={items.error} onRetry={() => items.refetch()} />

  return (
    <div className="space-y-3">
      {isLecturer && (
        <Button onClick={() => setCreating(true)}>
          <Plus /> New assignment
        </Button>
      )}
      {items.data.length === 0 && <EmptyState icon={ClipboardList} title="No assignments" hint="Assignments set by your lecturers will be listed here." />}
      {items.data.map((a) => (
        <Link key={a.id} to={`/assignments/${a.id}`} className="flex min-h-16 items-center gap-3 rounded-lg border p-3 hover:bg-muted/40">
          <ClipboardList className="size-5 shrink-0 text-muted-foreground" />
          <div className="min-w-0 flex-1">
            <p className="truncate font-medium">{a.title}</p>
            <p className="text-xs text-muted-foreground">
              {dueLabel(a.due_at)} · {fmtDateTime(a.due_at)} · {a.max_score} pts
            </p>
          </div>
          {isLecturer ? (
            <div className="flex items-center gap-2">
              {!a.published_at && <Badge variant="outline">Draft</Badge>}
              <Badge variant="secondary">{a.submissions_count ?? 0} submitted</Badge>
            </div>
          ) : (
            <AssignmentStatusBadge assignment={a} />
          )}
        </Link>
      ))}
      {creating && <AssignmentDialog courseId={course.id} onClose={() => setCreating(false)} />}
    </div>
  )
}
