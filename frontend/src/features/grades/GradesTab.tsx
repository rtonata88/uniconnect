import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, Eye, EyeOff, Loader2, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { toast } from 'sonner'
import { EmptyState, ErrorState, ListSkeleton } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { User } from '@/features/auth/types'
import type { CourseTabProps } from '@/features/courses/CoursePage'
import { api, errorMessage } from '@/lib/api'
import { repo } from '@/lib/repo'
import { fmtDateTime } from '@/lib/format'

export interface GradeItem {
  id: number
  course_id: number
  title: string
  source_type: 'assignment' | 'quiz' | 'manual'
  source_id: number | null
  source_url: string | null
  max_score: number
  weight_percent: number
  released_at: string | null
  updated_at: string
}
interface GradeCell {
  id: number
  score: number | null
  max_score: number
  comment: string | null
}
interface Gradebook {
  items: GradeItem[]
  rows: { student: User; grades: Record<number, GradeCell | null>; weighted_total: number | null }[]
  weights_total: number
}

export const gradeKeys = {
  book: (courseId: number) => ['courses', courseId, 'gradebook'] as const,
  mine: (courseId: number) => ['courses', courseId, 'my-grades'] as const,
}

const pct = (v: number | null) => (v === null ? '—' : `${v.toFixed(1)}%`)

function ManualItemDialog({ courseId, onClose }: { courseId: number; onClose: () => void }) {
  const qc = useQueryClient()
  const [title, setTitle] = useState('')
  const [max, setMax] = useState('10')
  const [weight, setWeight] = useState('0')
  const create = useMutation({
    mutationFn: () => api.post(`/courses/${courseId}/grade-items`, { title, max_score: Number(max), weight_percent: Number(weight) }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: gradeKeys.book(courseId) })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Add grade column</DialogTitle>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="gi-title">Title</FieldLabel>
            <Input id="gi-title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Participation" />
          </Field>
          <div className="grid grid-cols-2 gap-4">
            <Field>
              <FieldLabel htmlFor="gi-max">Max score</FieldLabel>
              <Input id="gi-max" type="number" min={1} value={max} onChange={(e) => setMax(e.target.value)} />
            </Field>
            <Field>
              <FieldLabel htmlFor="gi-w">Weight %</FieldLabel>
              <Input id="gi-w" type="number" min={0} max={100} value={weight} onChange={(e) => setWeight(e.target.value)} />
            </Field>
          </div>
        </FieldGroup>
        <DialogFooter>
          <Button disabled={!title || create.isPending} onClick={() => create.mutate()}>
            Add
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ManualCell({ item, student, cell, courseId }: { item: GradeItem; student: User; cell: GradeCell | null; courseId: number }) {
  const qc = useQueryClient()
  const [value, setValue] = useState(cell?.score?.toString() ?? '')
  const save = useMutation({
    mutationFn: () => api.put(`/grade-items/${item.id}/grades`, { student_id: student.id, score: value === '' ? null : Number(value) }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: gradeKeys.book(courseId) }),
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Input
      aria-label={`${item.title} for ${student.name}`}
      className="h-8 w-20"
      type="number"
      min={0}
      max={item.max_score}
      value={value}
      onChange={(e) => setValue(e.target.value)}
      onBlur={() => value !== (cell?.score?.toString() ?? '') && save.mutate()}
    />
  )
}

function WeightInput({ item, courseId }: { item: GradeItem; courseId: number }) {
  const qc = useQueryClient()
  const [value, setValue] = useState(String(item.weight_percent))
  const save = useMutation({
    mutationFn: () => api.patch(`/grade-items/${item.id}`, { weight_percent: Number(value) }),
    onSuccess: () => void qc.invalidateQueries({ queryKey: gradeKeys.book(courseId) }),
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <div className="flex items-center gap-1">
      <Input
        aria-label={`Weight of ${item.title}`}
        className="h-7 w-16 text-xs"
        type="number"
        min={0}
        max={100}
        value={value}
        onChange={(e) => setValue(e.target.value)}
        onBlur={() => Number(value) !== item.weight_percent && save.mutate()}
      />
      <span className="text-xs">%</span>
    </div>
  )
}

function LecturerGradebook({ courseId, courseCode }: { courseId: number; courseCode: string }) {
  const qc = useQueryClient()
  const [adding, setAdding] = useState(false)
  const [exporting, setExporting] = useState(false)
  const book = useQuery({ queryKey: gradeKeys.book(courseId), queryFn: async () => (await api.get<Gradebook>(`/courses/${courseId}/gradebook`)).data })
  const release = useMutation({
    mutationFn: ({ item, value }: { item: GradeItem; value: boolean }) => api.post(`/grade-items/${item.id}/release`, { release: value }),
    onSuccess: (_, { value, item }) => {
      toast.success(value ? `Grades for "${item.title}" released to students` : 'Grades hidden again')
      void qc.invalidateQueries({ queryKey: gradeKeys.book(courseId) })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const exportCsv = async () => {
    setExporting(true)
    try {
      const res = await api.get(`/courses/${courseId}/gradebook/export`, { responseType: 'blob' })
      const url = URL.createObjectURL(res.data as Blob)
      const a = Object.assign(document.createElement('a'), { href: url, download: `${courseCode}-gradebook.csv` })
      a.click()
      URL.revokeObjectURL(url)
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setExporting(false)
    }
  }

  if (book.isPending) return <ListSkeleton />
  if (book.isError) return <ErrorState error={book.error} onRetry={() => book.refetch()} />
  const { items, rows, weights_total } = book.data

  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start gap-2">
        <div className="flex-1">
          <CardTitle>Gradebook</CardTitle>
          <CardDescription>
            Weights total {weights_total}%{weights_total !== 100 && weights_total > 0 && ' (totals are normalised to the weights in use)'}. Students only see released columns.
          </CardDescription>
        </div>
        <Button variant="outline" onClick={() => setAdding(true)}>
          <Plus /> Column
        </Button>
        <Button variant="outline" onClick={exportCsv} disabled={exporting}>
          {exporting ? <Loader2 className="animate-spin" /> : <Download />} CSV
        </Button>
      </CardHeader>
      <CardContent>
        {items.length === 0 ? (
          <EmptyState title="No graded work yet" hint="Assignments and quizzes add columns automatically." />
        ) : (
          <div className="overflow-x-auto rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="sticky left-0 z-10 min-w-40 bg-background">Student</TableHead>
                  {items.map((item) => (
                    <TableHead key={item.id} className="min-w-36 align-top">
                      <div className="space-y-1 py-1">
                        {item.source_url ? (
                          <Link to={item.source_url} className="line-clamp-2 font-medium text-foreground hover:underline">
                            {item.title}
                          </Link>
                        ) : (
                          <p className="line-clamp-2 font-medium text-foreground">{item.title}</p>
                        )}
                        <p className="text-xs">/{item.max_score} · {item.source_type}</p>
                        <WeightInput key={item.updated_at} item={item} courseId={courseId} />
                        <Button
                          size="sm"
                          variant={item.released_at ? 'secondary' : 'default'}
                          className="h-7 text-xs"
                          onClick={() => release.mutate({ item, value: !item.released_at })}
                          disabled={release.isPending}
                        >
                          {item.released_at ? <><Eye /> Released</> : <><EyeOff /> Release</>}
                        </Button>
                      </div>
                    </TableHead>
                  ))}
                  <TableHead className="min-w-24">Total</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.student.id}>
                    <TableCell className="sticky left-0 z-10 bg-background font-medium">{r.student.name}</TableCell>
                    {items.map((item) => {
                      const cell = r.grades[item.id]
                      return (
                        <TableCell key={item.id}>
                          {item.source_type === 'manual' ? (
                            <ManualCell item={item} student={r.student} cell={cell} courseId={courseId} />
                          ) : cell?.score != null ? (
                            <span title={cell.comment ?? undefined}>{cell.score}</span>
                          ) : (
                            <span className="text-muted-foreground">—</span>
                          )}
                        </TableCell>
                      )
                    })}
                    <TableCell className="font-medium">{pct(r.weighted_total)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
      {adding && <ManualItemDialog courseId={courseId} onClose={() => setAdding(false)} />}
    </Card>
  )
}

export interface MyGrades {
  items: (GradeItem & { grade: GradeCell | null })[]
  weighted_total: number | null
}

export const myGradesLoader = { load: (courseId: number) => repo.getMyGrades(courseId) }

function StudentGrades({ courseId }: { courseId: number }) {
  const grades = useQuery({ queryKey: gradeKeys.mine(courseId), queryFn: () => myGradesLoader.load(courseId) })
  if (grades.isPending) return <ListSkeleton />
  if (grades.isError) return <ErrorState error={grades.error} onRetry={() => grades.refetch()} />
  if (grades.data.items.length === 0) return <EmptyState title="No grades released yet" hint="You'll be notified when your lecturer releases grades." />
  return (
    <Card>
      <CardHeader>
        <CardTitle>My grades</CardTitle>
        <CardDescription>Weighted total of released items: <strong className="text-foreground">{pct(grades.data.weighted_total)}</strong></CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="divide-y">
          {grades.data.items.map((item) => (
            <li key={item.id} className="flex min-h-14 items-center gap-3 py-2">
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium">{item.title}</p>
                <p className="text-xs text-muted-foreground">
                  Weight {item.weight_percent}% · released {item.released_at && fmtDateTime(item.released_at)}
                </p>
                {item.grade?.comment && <p className="mt-1 text-sm whitespace-pre-wrap text-muted-foreground">{item.grade.comment}</p>}
              </div>
              <Badge variant={item.grade?.score != null ? 'default' : 'outline'}>
                {item.grade?.score != null ? `${item.grade.score} / ${item.max_score}` : 'Not graded'}
              </Badge>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}

export function GradesTab({ course, isLecturer }: CourseTabProps) {
  return isLecturer ? <LecturerGradebook courseId={course.id} courseCode={course.code} /> : <StudentGrades courseId={course.id} />
}
