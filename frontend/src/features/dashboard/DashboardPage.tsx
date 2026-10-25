import { useQuery } from '@tanstack/react-query'
import { Activity, BookOpen, CalendarClock, CheckCircle2, ClipboardCheck, Megaphone, MessageCircle, Timer, Users } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { ErrorState, ListSkeleton, Page } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/features/auth/store'
import { api } from '@/lib/api'
import { getMeta, setMeta } from '@/lib/db'
import { dueLabel, fmtDateTime, fromNow } from '@/lib/format'
import { isOnline } from '@/lib/useOnline'

interface Dashboard {
  role: 'admin' | 'lecturer' | 'student'
  unread_chats: number
  recent_announcements: { id: number; course_id: number; course_code?: string; title: string; body: string; published_at: string; author: { name: string } | null }[]
  upcoming_deadlines?: { id: number; title: string; course_code: string; due_at: string; submitted: boolean }[]
  to_grade?: { id: number; title: string; course_code: string; ungraded: number }[]
  quiz_attempts_to_grade?: number
  recent_submissions?: { id: number; assignment_id: number; assignment: string; student: string; submitted_at: string; is_late: boolean }[]
  admin?: { users: number; students: number; lecturers: number; courses: number; messages_today: number; instances: Record<string, { total: number; healthy: number }> }
}

/** Last dashboard kept on the device so it still renders offline. */
async function loadDashboard(): Promise<Dashboard> {
  if (isOnline()) {
    try {
      const { data } = await api.get<Dashboard>('/dashboard')
      await setMeta('dashboard', data)
      return data
    } catch (e) {
      const cached = await getMeta<Dashboard>('dashboard')
      if (cached) return cached
      throw e
    }
  }
  const cached = await getMeta<Dashboard>('dashboard')
  if (!cached) throw new Error('Connect once to load your dashboard.')
  return cached
}

function Stat({ icon: Icon, label, value, to }: { icon: typeof Users; label: string; value: ReactNode; to?: string }) {
  const body = (
    <Card className="gap-1 py-4 transition-colors hover:bg-muted/40">
      <CardContent className="flex items-center gap-3">
        <span className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary">
          <Icon className="size-5" />
        </span>
        <div>
          <p className="text-2xl font-semibold tabular-nums">{value}</p>
          <p className="text-xs text-muted-foreground">{label}</p>
        </div>
      </CardContent>
    </Card>
  )
  return to ? <Link to={to}>{body}</Link> : body
}

function Announcements({ items }: { items: Dashboard['recent_announcements'] }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          <Megaphone className="size-4" /> Recent announcements
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {items.length === 0 && <p className="text-sm text-muted-foreground">Nothing new from your courses.</p>}
        {items.map((a) => (
          <Link key={a.id} to={`/courses/${a.course_id}/announcements`} className="block rounded-md border-b pb-3 last:border-0 last:pb-0 hover:opacity-80">
            <p className="text-sm font-medium">
              {a.course_code && <Badge variant="secondary" className="mr-2">{a.course_code}</Badge>}
              {a.title}
            </p>
            <p className="line-clamp-2 text-sm whitespace-pre-wrap text-muted-foreground">{a.body}</p>
            <p className="text-xs text-muted-foreground">
              {a.author?.name} · {fromNow(a.published_at)}
            </p>
          </Link>
        ))}
      </CardContent>
    </Card>
  )
}

export function DashboardPage() {
  const user = useAuth((s) => s.user)!
  const dash = useQuery({ queryKey: ['dashboard'], queryFn: loadDashboard, refetchInterval: 60_000 })
  const hour = new Date().getHours()
  const greeting = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening'

  return (
    <Page wide>
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">
          {greeting}, {user.name.replace(/^(Dr\.|Prof\.)\s*/, '').split(' ')[0]}
        </h1>
        <p className="text-sm text-muted-foreground">{new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
      </div>
      {dash.isPending ? (
        <ListSkeleton rows={4} />
      ) : dash.isError ? (
        <ErrorState error={dash.error} onRetry={() => dash.refetch()} />
      ) : (
        <DashboardBody d={dash.data} />
      )}
    </Page>
  )
}

function DashboardBody({ d }: { d: Dashboard }) {
  if (d.admin) {
    const inst = d.admin.instances
    const total = Object.values(inst).reduce((s, x) => s + x.total, 0)
    const healthy = Object.values(inst).reduce((s, x) => s + x.healthy, 0)
    return (
      <div className="space-y-4">
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Stat icon={Users} label={`${d.admin.students} students · ${d.admin.lecturers} lecturers`} value={d.admin.users} to="/admin/users" />
          <Stat icon={BookOpen} label="courses" value={d.admin.courses} to="/admin/courses" />
          <Stat icon={MessageCircle} label="messages today" value={d.admin.messages_today} />
          <Stat icon={Activity} label="instances healthy" value={`${healthy}/${total}`} to="/admin/system" />
        </div>
        <Card>
          <CardHeader>
            <CardTitle className="text-base">System health</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {Object.entries(inst).map(([svc, x]) => (
              <Badge key={svc} variant={x.healthy === x.total ? 'secondary' : 'destructive'} className="capitalize">
                {svc} {x.healthy}/{x.total}
              </Badge>
            ))}
            <Link to="/admin/system" className="ml-auto text-sm text-primary hover:underline">
              Open System Status →
            </Link>
          </CardContent>
        </Card>
        <Announcements items={d.recent_announcements} />
      </div>
    )
  }

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <div className="grid grid-cols-2 gap-3 lg:col-span-3 lg:grid-cols-4">
        <Stat icon={MessageCircle} label="unread chats" value={d.unread_chats} to="/chats" />
        {d.upcoming_deadlines && <Stat icon={CalendarClock} label="due in 14 days" value={d.upcoming_deadlines.filter((a) => !a.submitted).length} />}
        {d.to_grade && <Stat icon={ClipboardCheck} label="submissions to grade" value={d.to_grade.reduce((s, a) => s + a.ungraded, 0)} />}
        {d.quiz_attempts_to_grade !== undefined && <Stat icon={Timer} label="quiz answers to mark" value={d.quiz_attempts_to_grade} />}
      </div>

      {d.upcoming_deadlines && (
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <CalendarClock className="size-4" /> Upcoming deadlines
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {d.upcoming_deadlines.length === 0 && <p className="text-sm text-muted-foreground">No deadlines in the next two weeks.</p>}
            {d.upcoming_deadlines.map((a) => (
              <Link key={a.id} to={`/assignments/${a.id}`} className="flex items-center gap-3 rounded-md border p-3 hover:bg-muted/50">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{a.title}</p>
                  <p className="text-xs text-muted-foreground">
                    {a.course_code} · {dueLabel(a.due_at)}
                  </p>
                </div>
                {a.submitted ? <CheckCircle2 className="size-4 text-emerald-600" aria-label="submitted" /> : <Badge variant="outline">To do</Badge>}
              </Link>
            ))}
          </CardContent>
        </Card>
      )}

      {d.to_grade && (
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-base">
              <ClipboardCheck className="size-4" /> To grade
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-2">
            {d.to_grade.length === 0 && <p className="text-sm text-muted-foreground">All caught up.</p>}
            {d.to_grade.map((a) => (
              <Link key={a.id} to={`/assignments/${a.id}`} className="flex items-center gap-3 rounded-md border p-3 hover:bg-muted/50">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium">{a.title}</p>
                  <p className="text-xs text-muted-foreground">{a.course_code}</p>
                </div>
                <Badge>{a.ungraded}</Badge>
              </Link>
            ))}
          </CardContent>
        </Card>
      )}

      {d.recent_submissions && (
        <Card className="lg:col-span-1">
          <CardHeader>
            <CardTitle className="text-base">Recent activity</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="divide-y">
              {d.recent_submissions.length === 0 && <li className="text-sm text-muted-foreground">No submissions yet.</li>}
              {d.recent_submissions.map((s) => (
                <li key={s.id} className="py-2 text-sm">
                  <Link to={`/assignments/${s.assignment_id}`} className="hover:underline">
                    <span className="font-medium">{s.student}</span> submitted {s.assignment}
                  </Link>
                  <p className="text-xs text-muted-foreground">
                    {fmtDateTime(s.submitted_at)} {s.is_late && <Badge variant="destructive" className="ml-1">late</Badge>}
                  </p>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      )}

      <div className={d.upcoming_deadlines && !d.to_grade ? 'lg:col-span-2' : 'lg:col-span-3'}>
        <Announcements items={d.recent_announcements} />
      </div>
    </div>
  )
}
