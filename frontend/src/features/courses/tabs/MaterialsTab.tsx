import { useMutation, useQueryClient } from '@tanstack/react-query'
import { EyeOff, FileUp, Loader2, MoreVertical, Pencil, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'
import { EmptyState, ErrorState, ListSkeleton } from '@/components/common'
import { FileCard } from '@/components/FileCard'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Progress } from '@/components/ui/progress'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { api, errorMessage } from '@/lib/api'
import { uploadFile } from '@/lib/files'
import type { CourseTabProps } from '../CoursePage'
import { courseKeys, useMaterials } from '../api'
import type { Material } from '../types'
import { MaterialOfflineToggle } from './MaterialOfflineToggle'

const ACCEPT = '.pdf,.doc,.docx,.ppt,.pptx,.xlsx,.txt,image/*'

function MaterialDialog({
  courseId,
  material,
  open,
  onOpenChange,
}: {
  courseId: number
  material?: Material
  open: boolean
  onOpenChange: (o: boolean) => void
}) {
  const qc = useQueryClient()
  const [title, setTitle] = useState(material?.title ?? '')
  const [description, setDescription] = useState(material?.description ?? '')
  const [week, setWeek] = useState(String(material?.week ?? 1))
  const [publish, setPublish] = useState(material ? !!material.published_at : true)
  const [file, setFile] = useState<File | null>(null)
  const [progress, setProgress] = useState<number | null>(null)

  const save = useMutation({
    mutationFn: async () => {
      let attachment_id = material?.attachment?.id
      if (file) {
        setProgress(0)
        attachment_id = (await uploadFile(file, file.name, setProgress)).id
      }
      const body = { title, description: description || null, week: Number(week), publish, attachment_id }
      return material
        ? api.patch(`/materials/${material.id}`, { ...body, updated_at: material.updated_at })
        : api.post(`/courses/${courseId}/materials`, body)
    },
    onSuccess: () => {
      toast.success(material ? 'Material updated' : 'Material uploaded')
      void qc.invalidateQueries({ queryKey: courseKeys.materials(courseId) })
      onOpenChange(false)
    },
    onError: (e) => {
      if ((e as { response?: { status?: number } }).response?.status === 409) {
        void qc.invalidateQueries({ queryKey: courseKeys.materials(courseId) })
      }
      toast.error(errorMessage(e))
    },
    onSettled: () => setProgress(null),
  })

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{material ? 'Edit material' : 'Upload lecture notes'}</DialogTitle>
          <DialogDescription>PDF, Word, PowerPoint, Excel or images, up to 20 MB.</DialogDescription>
        </DialogHeader>
        <FieldGroup>
          <Field>
            <FieldLabel htmlFor="m-title">Title</FieldLabel>
            <Input id="m-title" value={title} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor="m-week">Week</FieldLabel>
            <Input id="m-week" type="number" min={0} max={60} value={week} onChange={(e) => setWeek(e.target.value)} className="w-28" />
          </Field>
          <Field>
            <FieldLabel htmlFor="m-desc">Description (optional)</FieldLabel>
            <Textarea id="m-desc" value={description} onChange={(e) => setDescription(e.target.value)} />
          </Field>
          <Field>
            <FieldLabel htmlFor="m-file">{material ? 'Replace file (optional)' : 'File'}</FieldLabel>
            <Input id="m-file" type="file" accept={ACCEPT} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            {progress !== null && <Progress value={progress} className="h-1.5" />}
          </Field>
          <Field orientation="horizontal">
            <Switch id="m-publish" checked={publish} onCheckedChange={setPublish} />
            <FieldLabel htmlFor="m-publish">Visible to students</FieldLabel>
          </Field>
        </FieldGroup>
        <DialogFooter>
          <Button disabled={save.isPending || !title || (!material && !file)} onClick={() => save.mutate()}>
            {save.isPending && <Loader2 className="animate-spin" />} {material ? 'Save' : 'Upload'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function MaterialsTab({ course, isLecturer }: CourseTabProps) {
  const materials = useMaterials(course.id)
  const qc = useQueryClient()
  const [dialog, setDialog] = useState<{ material?: Material } | null>(null)

  const remove = useMutation({
    mutationFn: (m: Material) => api.delete(`/materials/${m.id}`),
    onSuccess: () => {
      toast.success('Material deleted')
      void qc.invalidateQueries({ queryKey: courseKeys.materials(course.id) })
    },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (materials.isPending) return <ListSkeleton />
  if (materials.isError) return <ErrorState error={materials.error} onRetry={() => materials.refetch()} />

  const weeks = new Map<number, Material[]>()
  for (const m of materials.data) weeks.set(m.week, [...(weeks.get(m.week) ?? []), m])
  const sortedWeeks = [...weeks.keys()].sort((a, b) => a - b)

  return (
    <div className="space-y-5">
      {isLecturer && (
        <Button onClick={() => setDialog({})}>
          <FileUp /> Upload notes
        </Button>
      )}
      {materials.data.length === 0 && (
        <EmptyState title="No materials yet" hint={isLecturer ? 'Upload lecture notes for your students.' : 'Your lecturer has not shared any notes yet.'} />
      )}
      {sortedWeeks.map((week) => (
        <section key={week} className="space-y-2">
          <h2 className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">{week === 0 ? 'General' : `Week ${week}`}</h2>
          <div className="grid gap-2 md:grid-cols-2">
            {weeks.get(week)!.map((m) => (
              <div key={m.id} className="space-y-1 rounded-lg border p-3">
                <div className="flex items-start gap-2">
                  <div className="min-w-0 flex-1">
                    <p className="font-medium">{m.title}</p>
                    {m.description && <p className="text-sm whitespace-pre-wrap text-muted-foreground">{m.description}</p>}
                  </div>
                  {!m.published_at && (
                    <Badge variant="outline">
                      <EyeOff /> Draft
                    </Badge>
                  )}
                  {isLecturer && (
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-9" aria-label="Material actions">
                          <MoreVertical />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => setDialog({ material: m })}>
                          <Pencil /> Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem variant="destructive" onClick={() => confirm(`Delete "${m.title}"?`) && remove.mutate(m)}>
                          <Trash2 /> Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  )}
                </div>
                {m.attachment && <FileCard attachment={m.attachment} className="border-0 bg-muted/40" extra={<MaterialOfflineToggle material={m} />} />}
              </div>
            ))}
          </div>
        </section>
      ))}
      {dialog && (
        <MaterialDialog
          key={dialog.material?.id ?? 'new'}
          courseId={course.id}
          material={dialog.material}
          open
          onOpenChange={(o) => !o && setDialog(null)}
        />
      )}
    </div>
  )
}
