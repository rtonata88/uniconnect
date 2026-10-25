import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { Loader2, Plus, Search, Trash2, UserPlus } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { toast } from 'sonner'
import { DataTable } from '@/components/DataTable'
import { ErrorState, ListSkeleton, Page, PageHeader, UserAvatar } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import type { User } from '@/features/auth/types'
import { courseKeys, usePeople } from '@/features/courses/api'
import type { Course } from '@/features/courses/types'
import { api, errorMessage } from '@/lib/api'

const adminCoursesKey = ['admin', 'courses'] as const

function useUserSearch(role: string, search: string) {
  const q = useDeferredValue(search)
  return useQuery({
    queryKey: ['admin', 'users', 'picker', role, q],
    queryFn: async () => (await api.get<{ data: User[] }>('/admin/users', { params: { role, search: q || undefined, per_page: 100, active: 1 } })).data.data,
  })
}

function UserPicker({ role, selected, onToggle, exclude = [] }: { role: string; selected: number[]; onToggle: (id: number) => void; exclude?: number[] }) {
  const [search, setSearch] = useState('')
  const users = useUserSearch(role, search)
  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input placeholder={`Search ${role}s`} aria-label={`Search ${role}s`} className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      <ScrollArea className="h-56 rounded-md border">
        <ul className="p-1">
          {users.data
            ?.filter((u) => !exclude.includes(u.id))
            .map((u) => (
              <li key={u.id}>
                <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md px-2 hover:bg-muted">
                  <Checkbox checked={selected.includes(u.id)} onCheckedChange={() => onToggle(u.id)} />
                  <UserAvatar user={u} className="size-7" />
                  <span className="min-w-0 flex-1 truncate text-sm">{u.name}</span>
                  <span className="truncate text-xs text-muted-foreground">{u.student_number ?? u.email}</span>
                </label>
              </li>
            ))}
          {users.isPending && <li className="p-3 text-sm text-muted-foreground">Loading…</li>}
        </ul>
      </ScrollArea>
    </div>
  )
}

function CreateCourseDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const qc = useQueryClient()
  const [form, setForm] = useState({ code: '', title: '', semester: '2026 Semester 2', description: '' })
  const [lecturers, setLecturers] = useState<number[]>([])
  const create = useMutation({
    mutationFn: () => api.post('/admin/courses', { ...form, lecturer_ids: lecturers }),
    onSuccess: () => {
      toast.success(`${form.code.toUpperCase()} created`)
      void qc.invalidateQueries({ queryKey: adminCoursesKey })
      void qc.invalidateQueries({ queryKey: courseKeys.all })
      onOpenChange(false)
      setForm({ code: '', title: '', semester: '2026 Semester 2', description: '' })
      setLecturers([])
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => setForm({ ...form, [k]: e.target.value })
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90svh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>New course</DialogTitle>
        </DialogHeader>
        <FieldGroup>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field>
              <FieldLabel htmlFor="c-code">Code</FieldLabel>
              <Input id="c-code" value={form.code} onChange={set('code')} placeholder="DMS901" />
            </Field>
            <Field className="sm:col-span-2">
              <FieldLabel htmlFor="c-sem">Semester</FieldLabel>
              <Input id="c-sem" value={form.semester} onChange={set('semester')} />
            </Field>
          </div>
          <Field>
            <FieldLabel htmlFor="c-title">Title</FieldLabel>
            <Input id="c-title" value={form.title} onChange={set('title')} />
          </Field>
          <Field>
            <FieldLabel htmlFor="c-desc">Description</FieldLabel>
            <Textarea id="c-desc" value={form.description} onChange={set('description')} />
          </Field>
          <Field>
            <FieldLabel>Lecturers</FieldLabel>
            <UserPicker role="lecturer" selected={lecturers} onToggle={(id) => setLecturers((l) => (l.includes(id) ? l.filter((x) => x !== id) : [...l, id]))} />
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button disabled={create.isPending || !form.code || !form.title} onClick={() => create.mutate()}>
            {create.isPending && <Loader2 className="animate-spin" />} Create course
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function EnrolmentSheet({ course, onClose }: { course: Course | null; onClose: () => void }) {
  const qc = useQueryClient()
  const people = usePeople(course?.id ?? 0)
  const [adding, setAdding] = useState<'student' | 'lecturer' | null>(null)
  const [selected, setSelected] = useState<number[]>([])
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: courseKeys.people(course!.id) })
    void qc.invalidateQueries({ queryKey: adminCoursesKey })
  }
  const enrol = useMutation({
    mutationFn: () => api.post(`/admin/courses/${course!.id}/enrolments`, { user_ids: selected, role: adding }),
    onSuccess: (r) => {
      toast.success(r.data.message)
      setAdding(null)
      setSelected([])
      refresh()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  const remove = useMutation({
    mutationFn: (userId: number) => api.delete(`/admin/courses/${course!.id}/enrolments`, { data: { user_ids: [userId] } }),
    onSuccess: refresh,
    onError: (e) => toast.error(errorMessage(e)),
  })
  const enrolled = [...(people.data?.lecturers ?? []), ...(people.data?.students ?? [])].map((u) => u.id)

  const list = (users: User[] | undefined) => (
    <ul className="divide-y">
      {users?.map((u) => (
        <li key={u.id} className="flex min-h-12 items-center gap-3">
          <UserAvatar user={u} className="size-8" />
          <span className="min-w-0 flex-1 truncate text-sm">{u.name}</span>
          <Button variant="ghost" size="icon" className="size-9" aria-label={`Remove ${u.name}`} onClick={() => remove.mutate(u.id)}>
            <Trash2 />
          </Button>
        </li>
      ))}
    </ul>
  )

  return (
    <Sheet open={!!course} onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle>
            {course?.code} enrolments
          </SheetTitle>
          <SheetDescription>{course?.title}</SheetDescription>
        </SheetHeader>
        <div className="space-y-4 px-4 pb-6">
          {adding ? (
            <div className="space-y-3">
              <p className="text-sm font-medium">Add {adding}s</p>
              <UserPicker role={adding} selected={selected} exclude={enrolled} onToggle={(id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))} />
              <div className="flex gap-2">
                <Button disabled={!selected.length || enrol.isPending} onClick={() => enrol.mutate()}>
                  {enrol.isPending && <Loader2 className="animate-spin" />} Enrol {selected.length || ''}
                </Button>
                <Button variant="ghost" onClick={() => (setAdding(null), setSelected([]))}>
                  Cancel
                </Button>
              </div>
            </div>
          ) : (
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setAdding('student')}>
                <UserPlus /> Add students
              </Button>
              <Button variant="outline" onClick={() => setAdding('lecturer')}>
                <UserPlus /> Add lecturers
              </Button>
            </div>
          )}
          <Tabs defaultValue="students">
            <TabsList>
              <TabsTrigger value="students">Students ({people.data?.students.length ?? 0})</TabsTrigger>
              <TabsTrigger value="lecturers">Lecturers ({people.data?.lecturers.length ?? 0})</TabsTrigger>
            </TabsList>
            <TabsContent value="students">{list(people.data?.students)}</TabsContent>
            <TabsContent value="lecturers">{list(people.data?.lecturers)}</TabsContent>
          </Tabs>
        </div>
      </SheetContent>
    </Sheet>
  )
}

const columns: ColumnDef<Course, unknown>[] = [
  { accessorKey: 'code', header: 'Code', cell: ({ getValue }) => <Badge variant="secondary">{String(getValue())}</Badge> },
  { accessorKey: 'title', header: 'Title' },
  { id: 'lecturers', header: 'Lecturers', accessorFn: (c) => c.lecturers?.map((l) => l.name).join(', ') ?? '' },
  { accessorKey: 'students_count', header: 'Students' },
  { accessorKey: 'semester', header: 'Semester' },
]

export function CoursesAdminPage() {
  const [creating, setCreating] = useState(false)
  const [managing, setManaging] = useState<Course | null>(null)
  const [filter, setFilter] = useState('')
  const courses = useQuery({
    queryKey: adminCoursesKey,
    queryFn: async () => (await api.get<{ data: Course[] }>('/admin/courses')).data.data,
  })
  return (
    <Page wide>
      <PageHeader
        title="Courses & enrolments"
        description="Create courses, assign lecturers and enrol students. Each course gets its own group chat."
        actions={
          <Button onClick={() => setCreating(true)}>
            <Plus /> New course
          </Button>
        }
      />
      <Input placeholder="Filter courses" aria-label="Filter courses" value={filter} onChange={(e) => setFilter(e.target.value)} className="max-w-sm" />
      {courses.isPending ? (
        <ListSkeleton />
      ) : courses.isError ? (
        <ErrorState error={courses.error} onRetry={() => courses.refetch()} />
      ) : (
        <DataTable columns={columns} data={courses.data} filter={filter} onRowClick={setManaging} empty="No courses yet." />
      )}
      <CreateCourseDialog open={creating} onOpenChange={setCreating} />
      <EnrolmentSheet key={managing?.id ?? 'none'} course={managing} onClose={() => setManaging(null)} />
    </Page>
  )
}
