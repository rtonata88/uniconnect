import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { Fragment, useDeferredValue, useState } from 'react'
import { ErrorState, ListSkeleton, Page, PageHeader } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { fmtDateTime } from '@/lib/format'

interface AuditLog {
  id: number
  action: string
  user: { id: number; name: string; email: string } | null
  subject_type: string | null
  subject_id: number | null
  ip: string | null
  user_agent: string | null
  meta: Record<string, unknown> | null
  created_at: string
}

const ACTIONS = [
  ['all', 'All actions'],
  ['auth.', 'Sign-in (success / failure)'],
  ['auth.login_failed', 'Failed sign-ins'],
  ['user.', 'User changes'],
  ['user.role_changed', 'Role changes'],
  ['user.deactivated', 'Deactivations'],
  ['enrolment.', 'Enrolment changes'],
  ['grade.changed', 'Grade changes'],
  ['grade.released', 'Grade releases'],
  ['submission.', 'Submissions'],
  ['course.', 'Courses'],
] as const

const tone = (action: string) =>
  action.includes('failed') || action.includes('deactivated') ? 'destructive' : action.startsWith('grade') ? 'default' : 'secondary'

/** SEC-9: admin view of the audit log with filters. */
export function AuditLogPage() {
  const [action, setAction] = useState('all')
  const [userId, setUserId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<number | null>(null)
  const uid = useDeferredValue(userId)
  const logs = useQuery({
    queryKey: ['admin', 'audit', action, uid, from, to, page],
    queryFn: async () =>
      (
        await api.get<{ data: AuditLog[]; meta: { current_page: number; last_page: number; total: number } }>('/admin/audit-logs', {
          params: { action: action === 'all' ? undefined : action, user_id: uid || undefined, from: from || undefined, to: to || undefined, page },
        })
      ).data,
    placeholderData: keepPreviousData,
  })
  const reset = () => setPage(1)

  return (
    <Page wide>
      <PageHeader title="Audit log" description={logs.data ? `${logs.data.meta.total} entries` : 'Security-relevant actions'} />
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field>
          <FieldLabel htmlFor="al-action">Action</FieldLabel>
          <Select value={action} onValueChange={(v) => (setAction(v), reset())}>
            <SelectTrigger id="al-action" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {ACTIONS.map(([v, l]) => (
                <SelectItem key={v} value={v}>
                  {l}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </Field>
        <Field>
          <FieldLabel htmlFor="al-user">Actor user id</FieldLabel>
          <Input id="al-user" inputMode="numeric" value={userId} onChange={(e) => (setUserId(e.target.value.replace(/\D/g, '')), reset())} placeholder="any" />
        </Field>
        <Field>
          <FieldLabel htmlFor="al-from">From</FieldLabel>
          <Input id="al-from" type="date" value={from} onChange={(e) => (setFrom(e.target.value), reset())} />
        </Field>
        <Field>
          <FieldLabel htmlFor="al-to">To</FieldLabel>
          <Input id="al-to" type="date" value={to} onChange={(e) => (setTo(e.target.value), reset())} />
        </Field>
      </div>
      {logs.isPending ? (
        <ListSkeleton rows={8} />
      ) : logs.isError ? (
        <ErrorState error={logs.error} onRetry={() => logs.refetch()} />
      ) : (
        <>
          <div className="overflow-x-auto rounded-lg border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="w-8" />
                  <TableHead>When</TableHead>
                  <TableHead>Action</TableHead>
                  <TableHead>Actor</TableHead>
                  <TableHead>Subject</TableHead>
                  <TableHead>IP</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {logs.data.data.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={6} className="h-24 text-center text-muted-foreground">
                      No entries for these filters.
                    </TableCell>
                  </TableRow>
                )}
                {logs.data.data.map((l) => (
                  <Fragment key={l.id}>
                    <TableRow className="cursor-pointer" onClick={() => setOpen(open === l.id ? null : l.id)}>
                      <TableCell>{open === l.id ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}</TableCell>
                      <TableCell className="whitespace-nowrap">{fmtDateTime(l.created_at)}</TableCell>
                      <TableCell>
                        <Badge variant={tone(l.action)}>{l.action}</Badge>
                      </TableCell>
                      <TableCell>{l.user ? `${l.user.name} (#${l.user.id})` : <span className="text-muted-foreground">—</span>}</TableCell>
                      <TableCell>{l.subject_type ? `${l.subject_type} #${l.subject_id}` : '—'}</TableCell>
                      <TableCell className="font-mono text-xs">{l.ip}</TableCell>
                    </TableRow>
                    {open === l.id && (
                      <TableRow>
                        <TableCell colSpan={6} className="bg-muted/40">
                          <pre className="text-xs break-all whitespace-pre-wrap">{JSON.stringify({ meta: l.meta, user_agent: l.user_agent }, null, 2)}</pre>
                        </TableCell>
                      </TableRow>
                    )}
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </div>
          {logs.data.meta.last_page > 1 && (
            <div className="flex items-center justify-end gap-2 text-sm">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Previous
              </Button>
              <span>
                Page {logs.data.meta.current_page} of {logs.data.meta.last_page}
              </span>
              <Button variant="outline" size="sm" disabled={page >= logs.data.meta.last_page} onClick={() => setPage((p) => p + 1)}>
                Next
              </Button>
            </div>
          )}
        </>
      )}
    </Page>
  )
}
