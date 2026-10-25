import { zodResolver } from '@hookform/resolvers/zod'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import type { ColumnDef } from '@tanstack/react-table'
import { FileUp, Loader2, Plus, Search } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { DataTable } from '@/components/DataTable'
import { ErrorState, ListSkeleton, Page, PageHeader, UserAvatar } from '@/components/common'
import { applyServerErrors, TextField } from '@/components/form'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'
import type { User } from '@/features/auth/types'
import { useAuth } from '@/features/auth/store'
import { api, errorMessage } from '@/lib/api'

interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; total: number }
}

const roles = ['student', 'lecturer', 'admin'] as const

function RoleSelect({ value, onChange, id, includeAll }: { value: string; onChange: (v: string) => void; id?: string; includeAll?: boolean }) {
  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger id={id} className="w-full sm:w-40">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {includeAll && <SelectItem value="all">All roles</SelectItem>}
        {roles.map((r) => (
          <SelectItem key={r} value={r} className="capitalize">
            {r}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

const createSchema = z.object({
  name: z.string().trim().min(2, 'At least 2 characters'),
  email: z.email('Enter a valid email'),
  role: z.enum(roles),
  student_number: z.string().optional(),
  password: z.string().min(8, 'At least 8 characters'),
})

function CreateUserDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const qc = useQueryClient()
  const form = useForm<z.infer<typeof createSchema>>({
    resolver: zodResolver(createSchema),
    defaultValues: { name: '', email: '', role: 'student', student_number: '', password: '' },
  })
  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await api.post('/admin/users', { ...values, student_number: values.student_number || null })
      toast.success(`${values.name} created`)
      await qc.invalidateQueries({ queryKey: ['admin', 'users'] })
      form.reset()
      onOpenChange(false)
    } catch (e) {
      if (!applyServerErrors(e, form.setError)) toast.error(errorMessage(e))
    }
  })
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New user</DialogTitle>
          <DialogDescription>They can sign in straight away with this password.</DialogDescription>
        </DialogHeader>
        <form onSubmit={onSubmit} id="create-user">
          <FieldGroup>
            <TextField control={form.control} name="name" label="Full name" />
            <TextField control={form.control} name="email" label="Email" type="email" />
            <Controller
              control={form.control}
              name="role"
              render={({ field }) => (
                <Field>
                  <FieldLabel htmlFor="new-role">Role</FieldLabel>
                  <RoleSelect id="new-role" value={field.value} onChange={field.onChange} />
                </Field>
              )}
            />
            <TextField control={form.control} name="student_number" label="Student number (optional)" />
            <TextField control={form.control} name="password" label="Initial password" type="password" autoComplete="new-password" />
          </FieldGroup>
        </form>
        <DialogFooter>
          <Button type="submit" form="create-user" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />} Create user
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface ImportResult {
  message: string
  created: { id: number; email: string; password: string | null }[]
  errors: { line: number; email: string; errors: string[] }[]
}

function ImportDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const qc = useQueryClient()
  const [file, setFile] = useState<File | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const mutation = useMutation({
    mutationFn: async (f: File) => {
      const body = new FormData()
      body.append('file', f)
      try {
        return (await api.post<ImportResult>('/admin/users/import', body)).data
      } catch (e) {
        const data = (e as { response?: { status: number; data: ImportResult } }).response
        if (data?.status === 422 && data.data.errors) return data.data
        throw e
      }
    },
    onSuccess: (r) => {
      setResult(r)
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  const close = (o: boolean) => {
    if (!o) {
      setFile(null)
      setResult(null)
    }
    onOpenChange(o)
  }

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Import users from CSV</DialogTitle>
          <DialogDescription>
            Header row: <code>name,email,role,student_number,password</code>. Password is optional; a random one is generated.
          </DialogDescription>
        </DialogHeader>
        {result ? (
          <div className="max-h-80 space-y-3 overflow-y-auto text-sm">
            <p className="font-medium">{result.message}</p>
            {result.created.some((c) => c.password) && (
              <div>
                <p className="mb-1 text-muted-foreground">Generated passwords (shown once):</p>
                <ul className="space-y-0.5 font-mono text-xs">
                  {result.created
                    .filter((c) => c.password)
                    .map((c) => (
                      <li key={c.id}>
                        {c.email} — {c.password}
                      </li>
                    ))}
                </ul>
              </div>
            )}
            {result.errors.length > 0 && (
              <ul className="space-y-1 text-destructive">
                {result.errors.map((err) => (
                  <li key={err.line}>
                    Line {err.line} ({err.email || 'no email'}): {err.errors.join(' ')}
                  </li>
                ))}
              </ul>
            )}
          </div>
        ) : (
          <Input type="file" accept=".csv,text/csv" aria-label="CSV file" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
        )}
        <DialogFooter>
          {result ? (
            <Button onClick={() => close(false)}>Done</Button>
          ) : (
            <Button disabled={!file || mutation.isPending} onClick={() => file && mutation.mutate(file)}>
              {mutation.isPending && <Loader2 className="animate-spin" />} Import
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function EditUserSheet({ user, onClose }: { user: User | null; onClose: () => void }) {
  const qc = useQueryClient()
  const me = useAuth((s) => s.user)
  const [role, setRole] = useState(user?.role ?? 'student')
  const [active, setActive] = useState(user?.is_active ?? true)
  const [password, setPassword] = useState('')
  const self = me?.id === user?.id
  const save = useMutation({
    mutationFn: () =>
      api.patch(`/admin/users/${user!.id}`, { role, is_active: active, ...(password ? { password } : {}) }),
    onSuccess: () => {
      toast.success('User updated')
      void qc.invalidateQueries({ queryKey: ['admin', 'users'] })
      onClose()
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  return (
    <Sheet open={!!user} onOpenChange={(o) => !o && onClose()}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>{user?.name}</SheetTitle>
          <SheetDescription>{user?.email}</SheetDescription>
        </SheetHeader>
        <FieldGroup className="px-4">
          <Field>
            <FieldLabel htmlFor="edit-role">Role</FieldLabel>
            <RoleSelect id="edit-role" value={role} onChange={(v) => setRole(v as typeof role)} />
          </Field>
          <Field orientation="horizontal">
            <Switch id="edit-active" checked={active} onCheckedChange={setActive} disabled={self} />
            <FieldLabel htmlFor="edit-active">Active (deactivating signs the user out everywhere)</FieldLabel>
          </Field>
          <Field>
            <FieldLabel htmlFor="edit-password">Set new password (optional)</FieldLabel>
            <Input id="edit-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password" />
          </Field>
        </FieldGroup>
        <SheetFooter>
          <Button onClick={() => save.mutate()} disabled={save.isPending}>
            {save.isPending && <Loader2 className="animate-spin" />} Save changes
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

const columns: ColumnDef<User, unknown>[] = [
  {
    accessorKey: 'name',
    header: 'Name',
    cell: ({ row }) => (
      <div className="flex items-center gap-2">
        <UserAvatar user={row.original} className="size-8" />
        <div className="min-w-0">
          <p className="truncate font-medium">{row.original.name}</p>
          <p className="truncate text-xs text-muted-foreground">{row.original.email}</p>
        </div>
      </div>
    ),
  },
  { accessorKey: 'role', header: 'Role', cell: ({ getValue }) => <Badge variant="outline" className="capitalize">{String(getValue())}</Badge> },
  { accessorKey: 'student_number', header: 'Student no.', cell: ({ getValue }) => (getValue() as string) || '—' },
  {
    accessorKey: 'is_active',
    header: 'Status',
    cell: ({ getValue }) => (getValue() ? <Badge variant="secondary">Active</Badge> : <Badge variant="destructive">Deactivated</Badge>),
  },
]

export function UsersPage() {
  const [search, setSearch] = useState('')
  const [role, setRole] = useState('all')
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [importing, setImporting] = useState(false)
  const [editing, setEditing] = useState<User | null>(null)
  const q = useDeferredValue(search)

  const users = useQuery({
    queryKey: ['admin', 'users', q, role, page],
    queryFn: async () =>
      (await api.get<Paginated<User>>('/admin/users', { params: { search: q || undefined, role: role === 'all' ? undefined : role, page } })).data,
    placeholderData: keepPreviousData,
  })

  return (
    <Page wide>
      <PageHeader
        title="Users"
        description={users.data ? `${users.data.meta.total} accounts` : undefined}
        actions={
          <>
            <Button variant="outline" onClick={() => setImporting(true)}>
              <FileUp /> Import CSV
            </Button>
            <Button onClick={() => setCreating(true)}>
              <Plus /> New user
            </Button>
          </>
        }
      />
      <div className="flex flex-col gap-2 sm:flex-row">
        <div className="relative flex-1">
          <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            aria-label="Search users"
            placeholder="Search name, email or student number"
            className="pl-9"
            value={search}
            onChange={(e) => (setSearch(e.target.value), setPage(1))}
          />
        </div>
        <RoleSelect value={role} onChange={(v) => (setRole(v), setPage(1))} includeAll />
      </div>
      {users.isPending ? (
        <ListSkeleton rows={6} />
      ) : users.isError ? (
        <ErrorState error={users.error} onRetry={() => users.refetch()} />
      ) : (
        <>
          <DataTable columns={columns} data={users.data.data} onRowClick={setEditing} empty="No users match your search." />
          {users.data.meta.last_page > 1 && (
            <div className="flex items-center justify-end gap-2 text-sm">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span>
                Page {users.data.meta.current_page} of {users.data.meta.last_page}
              </span>
              <Button variant="outline" size="sm" disabled={page >= users.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          )}
        </>
      )}
      <CreateUserDialog open={creating} onOpenChange={setCreating} />
      <ImportDialog open={importing} onOpenChange={setImporting} />
      <EditUserSheet key={editing?.id ?? 'none'} user={editing} onClose={() => setEditing(null)} />
    </Page>
  )
}
