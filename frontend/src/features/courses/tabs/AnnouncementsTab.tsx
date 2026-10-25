import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Loader2, Megaphone, MoreVertical, Pencil, Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { EmptyState, ErrorState, ListSkeleton, UserAvatar } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader } from '@/components/ui/card'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { api, errorMessage } from '@/lib/api'
import { fmtDateTime } from '@/lib/format'
import type { CourseTabProps } from '../CoursePage'
import { courseKeys, useAnnouncements } from '../api'
import type { Announcement } from '../types'
import { announcementWriter } from './announcementWriter'

function AnnouncementDialog({
  courseId,
  announcement,
  onClose,
}: {
  courseId: number
  announcement?: Announcement
  onClose: () => void
}) {
  const qc = useQueryClient()
  const [title, setTitle] = useState(announcement?.title ?? '')
  const [body, setBody] = useState(announcement?.body ?? '')
  const [publish, setPublish] = useState(announcement ? !!announcement.published_at : true)
  const save = useMutation({
    mutationFn: () =>
      announcement
        ? api.patch(`/announcements/${announcement.id}`, { title, body, publish, updated_at: announcement.updated_at })
        : announcementWriter.create(courseId, { title, body, publish }),
    onSuccess: (res) => {
      const queued = res === 'queued'
      toast.success(queued ? 'Saved — it will be posted when you are back online' : announcement ? 'Announcement updated' : 'Announcement posted')
      void qc.invalidateQueries({ queryKey: courseKeys.announcements(courseId) })
      onClose()
    },
    onError: (e) => {
      if ((e as { response?: { status?: number } }).response?.status === 409) {
        void qc.invalidateQueries({ queryKey: courseKeys.announcements(courseId) })
      }
      toast.error(errorMessage(e))
    },
  })
  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{announcement ? 'Edit announcement' : 'New announcement'}</DialogTitle>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="a-title">Title</FieldLabel>
            <Input id="a-title" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={200} />
          </Field>
          <Field>
            <FieldLabel htmlFor="a-body">Message</FieldLabel>
            <Textarea id="a-body" value={body} onChange={(e) => setBody(e.target.value)} rows={6} />
          </Field>
          <Field orientation="horizontal">
            <Switch id="a-publish" checked={publish} onCheckedChange={setPublish} />
            <FieldLabel htmlFor="a-publish">Publish now and notify students</FieldLabel>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button onClick={() => save.mutate()} disabled={save.isPending || !title.trim() || !body.trim()}>
            {save.isPending && <Loader2 className="animate-spin" />} {announcement ? 'Save' : publish ? 'Post' : 'Save draft'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function AnnouncementsTab({ course, isLecturer }: CourseTabProps) {
  const items = useAnnouncements(course.id)
  const qc = useQueryClient()
  const [dialog, setDialog] = useState<{ announcement?: Announcement } | null>(null)
  const remove = useMutation({
    mutationFn: (a: Announcement) => api.delete(`/announcements/${a.id}`),
    onSuccess: () => void qc.invalidateQueries({ queryKey: courseKeys.announcements(course.id) }),
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (items.isPending) return <ListSkeleton />
  if (items.isError) return <ErrorState error={items.error} onRetry={() => items.refetch()} />

  return (
    <div className="space-y-4">
      {isLecturer && (
        <Button onClick={() => setDialog({})}>
          <Plus /> New announcement
        </Button>
      )}
      {items.data.length === 0 && <EmptyState icon={Megaphone} title="No announcements" hint="Course news from your lecturers will appear here." />}
      {items.data.map((a) => (
        <Card key={a.id}>
          <CardHeader className="flex flex-row items-start gap-3">
            <UserAvatar user={a.author} className="size-9" />
            <div className="min-w-0 flex-1">
              <p className="font-medium">{a.title}</p>
              <p className="text-xs text-muted-foreground">
                {a.author?.name} · {a.published_at ? fmtDateTime(a.published_at) : 'Not published'}
              </p>
            </div>
            {a.pending && <Badge variant="outline">Waiting to send</Badge>}
            {!a.published_at && !a.pending && <Badge variant="outline">Draft</Badge>}
            {isLecturer && !a.pending && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="ghost" size="icon" className="size-9" aria-label="Announcement actions">
                    <MoreVertical />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => setDialog({ announcement: a })}>
                    <Pencil /> Edit
                  </DropdownMenuItem>
                  <DropdownMenuItem variant="destructive" onClick={() => confirm('Delete this announcement?') && remove.mutate(a)}>
                    <Trash2 /> Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </CardHeader>
          <CardContent>
            <p className="text-sm whitespace-pre-wrap">{a.body}</p>
          </CardContent>
        </Card>
      ))}
      {dialog && <AnnouncementDialog courseId={course.id} announcement={dialog.announcement} onClose={() => setDialog(null)} />}
    </div>
  )
}
