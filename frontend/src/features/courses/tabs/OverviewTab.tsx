import { CalendarClock, Megaphone } from 'lucide-react'
import { Link } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { dueLabel, fmtDateTime, fromNow } from '@/lib/format'
import type { CourseTabProps } from '../CoursePage'
import { useAnnouncements, useAssignments } from '../api'

export function OverviewTab({ course }: CourseTabProps) {
  const assignments = useAssignments(course.id)
  const announcements = useAnnouncements(course.id)
  const upcoming = (assignments.data ?? []).filter((a) => new Date(a.due_at) > new Date()).slice(0, 4)
  const recent = (announcements.data ?? []).slice(0, 3)

  return (
    <div className="grid gap-4 lg:grid-cols-3">
      <Card className="lg:col-span-3">
        <CardContent className="space-y-2">
          <p className="whitespace-pre-wrap text-sm">{course.description || 'No description.'}</p>
          <p className="text-sm text-muted-foreground">
            {course.semester} · Lecturers: {course.lecturers?.map((l) => l.name).join(', ') || '—'}
          </p>
        </CardContent>
      </Card>
      <Card className="lg:col-span-1">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <CalendarClock className="size-4" /> Upcoming deadlines
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2">
          {upcoming.length === 0 && <p className="text-sm text-muted-foreground">Nothing due soon.</p>}
          {upcoming.map((a) => (
            <Link key={a.id} to={`/assignments/${a.id}`} className="block rounded-md border p-3 hover:bg-muted/50">
              <p className="text-sm font-medium">{a.title}</p>
              <p className="text-xs text-muted-foreground">
                {dueLabel(a.due_at)} · {fmtDateTime(a.due_at)}
              </p>
              {a.my_submission && <Badge variant="secondary" className="mt-1">Submitted</Badge>}
            </Link>
          ))}
        </CardContent>
      </Card>
      <Card className="lg:col-span-2">
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            <Megaphone className="size-4" /> Recent announcements
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          {recent.length === 0 && <p className="text-sm text-muted-foreground">No announcements yet.</p>}
          {recent.map((a) => (
            <div key={a.id} className="border-b pb-3 last:border-0 last:pb-0">
              <p className="text-sm font-medium">{a.title}</p>
              <p className="line-clamp-2 text-sm whitespace-pre-wrap text-muted-foreground">{a.body}</p>
              <p className="mt-1 text-xs text-muted-foreground">{a.published_at ? fromNow(a.published_at) : 'Draft'}</p>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  )
}
