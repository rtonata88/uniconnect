import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, BellOff, Loader2, LogOut, UserMinus, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { UserAvatar } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Switch } from '@/components/ui/switch'
import { useAuth } from '@/features/auth/store'
import { api, errorMessage } from '@/lib/api'
import { db } from '@/lib/db'
import { fromNow } from '@/lib/format'
import { refreshConversation, removeConversationLocally } from '../data'
import { useChatStore } from '../store'
import type { Conversation } from '../types'
import { ContactPicker } from './NewChatDialog'

export function GroupInfoSheet({ conversationId, onClose }: { conversationId: number; onClose: () => void }) {
  const me = useAuth((s) => s.user)!
  const qc = useQueryClient()
  const navigate = useNavigate()
  const online = useChatStore((s) => s.online)
  const [adding, setAdding] = useState(false)
  const [picked, setPicked] = useState<number[]>([])
  const conv = useQuery({
    queryKey: ['conversation', conversationId],
    queryFn: async () => {
      const c = await refreshConversation(conversationId)
      if (!c) throw new Error('You are no longer in this chat.')
      return c
    },
  })
  const [title, setTitle] = useState<string | null>(null)
  const c = conv.data
  const admin = c?.my_role === 'admin'
  const refresh = () => void qc.invalidateQueries({ queryKey: ['conversation', conversationId] })

  const run = useMutation({
    mutationFn: (fn: () => Promise<unknown>) => fn(),
    onSuccess: refresh,
    onError: (e) => toast.error(errorMessage(e)),
  })
  const mute = (hours: number) =>
    run.mutate(async () => {
      const { data } = await api.post<{ data: Conversation }>(`/conversations/${conversationId}/mute`, { hours })
      await db.conversations.put(data.data)
      toast.success(hours ? 'Muted — no push notifications for this chat' : 'Unmuted')
    })
  const muted = c?.muted_until && new Date(c.muted_until) > new Date()

  return (
    <Sheet open onOpenChange={(o) => !o && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{c?.title ?? 'Chat info'}</SheetTitle>
          <SheetDescription>
            {c?.type === 'course' ? 'Course chat — members follow the course enrolment.' : c?.type === 'group' ? `Group · ${c.participant_count} members` : 'Direct chat'}
          </SheetDescription>
        </SheetHeader>
        {!c ? (
          <div className="p-4">{conv.isError ? <p className="text-sm text-destructive">{errorMessage(conv.error)}</p> : <Loader2 className="animate-spin" />}</div>
        ) : (
          <div className="space-y-5 px-4 pb-8">
            <div className="flex flex-wrap gap-2">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline">{muted ? <BellOff /> : <Bell />} {muted ? `Muted until ${new Date(c.muted_until!).toLocaleString()}` : 'Mute'}</Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  {muted && <DropdownMenuItem onClick={() => mute(0)}>Unmute</DropdownMenuItem>}
                  <DropdownMenuItem onClick={() => mute(8)}>8 hours</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => mute(24 * 7)}>1 week</DropdownMenuItem>
                  <DropdownMenuItem onClick={() => mute(24 * 365)}>Always</DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>
              {c.type === 'group' && (
                <Button
                  variant="destructive"
                  onClick={() =>
                    confirm('Leave this group?') &&
                    run.mutate(async () => {
                      await api.post(`/conversations/${c.id}/leave`)
                      await removeConversationLocally(c.id)
                      onClose()
                      navigate('/chats')
                    })
                  }
                >
                  <LogOut /> Leave group
                </Button>
              )}
            </div>

            {admin && c.type === 'group' && (
              <Field>
                <FieldLabel htmlFor="g-title">Group name</FieldLabel>
                <div className="flex gap-2">
                  <Input id="g-title" value={title ?? c.title} onChange={(e) => setTitle(e.target.value)} />
                  <Button disabled={!title || title === c.title} onClick={() => run.mutate(() => api.patch(`/conversations/${c.id}`, { title }))}>
                    Save
                  </Button>
                </div>
              </Field>
            )}
            {admin && c.type === 'course' && (
              <Field orientation="horizontal">
                <Switch
                  id="g-ann"
                  checked={c.announcements_only}
                  onCheckedChange={(v) => run.mutate(() => api.patch(`/conversations/${c.id}`, { announcements_only: v }))}
                />
                <FieldLabel htmlFor="g-ann">Announcements only (only lecturers can post)</FieldLabel>
              </Field>
            )}

            <section className="space-y-2">
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-semibold">Members</h3>
                {admin && c.type === 'group' && !adding && (
                  <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                    <UserPlus /> Add
                  </Button>
                )}
              </div>
              {adding && (
                <div className="space-y-2 rounded-md border p-2">
                  <ContactPicker multiple selected={picked} onChange={setPicked} exclude={c.participants?.map((p) => p.id) ?? []} />
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      disabled={!picked.length}
                      onClick={() =>
                        run.mutate(async () => {
                          await api.post(`/conversations/${c.id}/participants`, { user_ids: picked })
                          setPicked([])
                          setAdding(false)
                        })
                      }
                    >
                      Add {picked.length || ''}
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setAdding(false)}>
                      Cancel
                    </Button>
                  </div>
                </div>
              )}
              <ul className="divide-y">
                {c.participants?.map((p) => (
                  <li key={p.id} className="flex min-h-12 items-center gap-3 py-1.5">
                    <UserAvatar user={p} className="size-9" online={online.has(p.id)} />
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-medium">
                        {p.name} {p.id === me.id && <span className="text-muted-foreground">(you)</span>}
                      </p>
                      <p className="text-xs text-muted-foreground">
                        {online.has(p.id) ? 'online' : p.last_seen_at ? `last seen ${fromNow(p.last_seen_at)}` : ''}
                      </p>
                    </div>
                    {p.role === 'admin' && <Badge variant="secondary">{c.type === 'course' ? 'Lecturer' : 'Admin'}</Badge>}
                    {admin && c.type === 'group' && p.id !== me.id && (
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-9"
                        aria-label={`Remove ${p.name}`}
                        onClick={() => confirm(`Remove ${p.name}?`) && run.mutate(() => api.delete(`/conversations/${c.id}/participants/${p.id}`))}
                      >
                        <UserMinus />
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          </div>
        )}
      </SheetContent>
    </Sheet>
  )
}
