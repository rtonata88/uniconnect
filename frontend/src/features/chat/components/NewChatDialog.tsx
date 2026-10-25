import { useMutation, useQuery } from '@tanstack/react-query'
import { Check, Loader2, Search, Users } from 'lucide-react'
import { useDeferredValue, useState } from 'react'
import { useNavigate } from 'react-router'
import { toast } from 'sonner'
import { UserAvatar } from '@/components/common'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import type { User } from '@/features/auth/types'
import { api, errorMessage } from '@/lib/api'
import { db } from '@/lib/db'
import { cn } from '@/lib/utils'
import type { Conversation } from '../types'

/** People who share a course with me (MSG-2). */
export function ContactPicker({
  multiple,
  selected,
  onChange,
  onPick,
  exclude = [],
}: {
  multiple?: boolean
  selected?: number[]
  onChange?: (ids: number[]) => void
  onPick?: (u: User) => void
  exclude?: number[]
}) {
  const [search, setSearch] = useState('')
  const q = useDeferredValue(search)
  const contacts = useQuery({
    queryKey: ['contacts', q],
    queryFn: async () => (await api.get<{ data: User[] }>('/contacts', { params: { search: q || undefined } })).data.data,
  })
  return (
    <div className="space-y-2">
      <div className="relative">
        <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input placeholder="Search people" aria-label="Search people" className="pl-9" value={search} onChange={(e) => setSearch(e.target.value)} autoFocus />
      </div>
      <ScrollArea className="h-72">
        <ul>
          {contacts.data
            ?.filter((u) => !exclude.includes(u.id))
            .map((u) => {
              const on = selected?.includes(u.id)
              return (
                <li key={u.id}>
                  <button
                    type="button"
                    className={cn('flex min-h-12 w-full items-center gap-3 rounded-md px-2 text-left hover:bg-muted', on && 'bg-muted')}
                    onClick={() => (multiple ? onChange?.(on ? selected!.filter((x) => x !== u.id) : [...(selected ?? []), u.id]) : onPick?.(u))}
                  >
                    <UserAvatar user={u} className="size-9" />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium">{u.name}</span>
                      <span className="block truncate text-xs text-muted-foreground capitalize">{u.role}</span>
                    </span>
                    {on && <Check className="size-4 text-primary" />}
                  </button>
                </li>
              )
            })}
          {contacts.isPending && <li className="p-3 text-sm text-muted-foreground">Loading…</li>}
          {contacts.data?.length === 0 && <li className="p-3 text-sm text-muted-foreground">Nobody found. You can chat with people who share a course with you.</li>}
        </ul>
      </ScrollArea>
    </div>
  )
}

export function NewChatDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (o: boolean) => void }) {
  const navigate = useNavigate()
  const [title, setTitle] = useState('')
  const [members, setMembers] = useState<number[]>([])
  const create = useMutation({
    mutationFn: async (body: Record<string, unknown>) => (await api.post<{ data: Conversation }>('/conversations', body)).data.data,
    onSuccess: async (c) => {
      await db.conversations.put(c)
      onOpenChange(false)
      setTitle('')
      setMembers([])
      navigate(`/chats/${c.id}`)
    },
    onError: (e) => toast.error(errorMessage(e)),
  })
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>New chat</DialogTitle>
          <DialogDescription>Chat with lecturers and classmates from your courses.</DialogDescription>
        </DialogHeader>
        <Tabs defaultValue="direct">
          <TabsList className="w-full">
            <TabsTrigger value="direct">Private chat</TabsTrigger>
            <TabsTrigger value="group">
              <Users /> New group
            </TabsTrigger>
          </TabsList>
          <TabsContent value="direct">
            <ContactPicker onPick={(u) => create.mutate({ type: 'direct', user_id: u.id })} />
          </TabsContent>
          <TabsContent value="group" className="space-y-3">
            <Input placeholder="Group name" aria-label="Group name" value={title} onChange={(e) => setTitle(e.target.value)} maxLength={100} />
            <ContactPicker multiple selected={members} onChange={setMembers} />
            <DialogFooter>
              <Button disabled={!title.trim() || !members.length || create.isPending} onClick={() => create.mutate({ type: 'group', title, user_ids: members })}>
                {create.isPending && <Loader2 className="animate-spin" />} Create group ({members.length})
              </Button>
            </DialogFooter>
          </TabsContent>
        </Tabs>
      </DialogContent>
    </Dialog>
  )
}
