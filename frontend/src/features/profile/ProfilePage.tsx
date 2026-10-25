import { zodResolver } from '@hookform/resolvers/zod'
import { Camera, Loader2, LogOut, MonitorSmartphone } from 'lucide-react'
import { useRef, useState } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { applyServerErrors, TextField } from '@/components/form'
import { Page, PageHeader, UserAvatar } from '@/components/common'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { FieldGroup } from '@/components/ui/field'
import { Progress } from '@/components/ui/progress'
import { passwordRule } from '@/features/auth/PasswordPages'
import { useAuth } from '@/features/auth/store'
import type { Me } from '@/features/auth/types'
import { api, errorMessage } from '@/lib/api'
import { profileExtras } from './extras'

function AvatarCard({ user }: { user: Me }) {
  const setUser = useAuth((s) => s.setUser)
  const input = useRef<HTMLInputElement>(null)
  const [progress, setProgress] = useState<number | null>(null)

  const upload = async (file: File) => {
    const body = new FormData()
    body.append('avatar', file)
    setProgress(0)
    try {
      const { data } = await api.post<{ data: Me }>('/me/avatar', body, {
        onUploadProgress: (e) => setProgress(e.total ? Math.round((e.loaded / e.total) * 100) : null),
      })
      setUser({ ...user, ...data.data })
      toast.success('Profile photo updated')
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setProgress(null)
    }
  }

  return (
    <Card>
      <CardContent className="flex items-center gap-4">
        <UserAvatar user={user} className="size-16 text-lg" />
        <div className="min-w-0 flex-1">
          <p className="truncate font-medium">{user.name}</p>
          <p className="truncate text-sm text-muted-foreground">{user.email}</p>
          <Badge variant="secondary" className="mt-1 capitalize">
            {user.role}
          </Badge>
          {progress !== null && <Progress value={progress} className="mt-2 h-1.5" />}
        </div>
        <input
          ref={input}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="hidden"
          onChange={(e) => {
            const f = e.target.files?.[0]
            if (f) void upload(f)
            e.target.value = ''
          }}
        />
        <Button variant="outline" size="icon" className="size-11" aria-label="Change photo" onClick={() => input.current?.click()}>
          <Camera />
        </Button>
      </CardContent>
    </Card>
  )
}

function NameCard({ user }: { user: Me }) {
  const setUser = useAuth((s) => s.setUser)
  const form = useForm<{ name: string }>({
    resolver: zodResolver(z.object({ name: z.string().trim().min(2, 'At least 2 characters').max(120) })),
    defaultValues: { name: user.name },
  })
  const onSubmit = form.handleSubmit(async (values) => {
    try {
      const { data } = await api.patch<{ data: Me }>('/me', values)
      setUser({ ...user, ...data.data })
      toast.success('Name saved')
    } catch (e) {
      if (!applyServerErrors(e, form.setError)) toast.error(errorMessage(e))
    }
  })
  return (
    <Card>
      <CardHeader>
        <CardTitle>Profile</CardTitle>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit}>
          <FieldGroup>
            <TextField control={form.control} name="name" label="Display name" autoComplete="name" />
            <Button type="submit" className="self-start" disabled={form.formState.isSubmitting}>
              Save
            </Button>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}

const pwSchema = z
  .object({ current_password: z.string().min(1, 'Required'), password: passwordRule, password_confirmation: z.string() })
  .refine((v) => v.password === v.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match' })

function PasswordCard() {
  const form = useForm<z.infer<typeof pwSchema>>({
    resolver: zodResolver(pwSchema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await api.patch('/me', values)
      form.reset()
      toast.success('Password changed. Other devices were signed out.')
    } catch (e) {
      if (!applyServerErrors(e, form.setError)) toast.error(errorMessage(e))
    }
  })
  return (
    <Card>
      <CardHeader>
        <CardTitle>Password</CardTitle>
        <CardDescription>Changing it signs out your other devices.</CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={onSubmit}>
          <FieldGroup>
            <TextField control={form.control} name="current_password" label="Current password" type="password" autoComplete="current-password" />
            <TextField control={form.control} name="password" label="New password" type="password" autoComplete="new-password" />
            <TextField control={form.control} name="password_confirmation" label="Confirm new password" type="password" autoComplete="new-password" />
            <Button type="submit" className="self-start" disabled={form.formState.isSubmitting}>
              Change password
            </Button>
          </FieldGroup>
        </form>
      </CardContent>
    </Card>
  )
}

function SessionCard() {
  const logout = useAuth((s) => s.logout)
  const [busy, setBusy] = useState<'one' | 'all' | null>(null)
  return (
    <Card>
      <CardHeader>
        <CardTitle>Sessions</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-2 sm:flex-row">
        <Button variant="outline" size="lg" disabled={!!busy} onClick={() => (setBusy('one'), logout())}>
          {busy === 'one' ? <Loader2 className="animate-spin" /> : <LogOut />} Log out
        </Button>
        <Button variant="destructive" size="lg" disabled={!!busy} onClick={() => (setBusy('all'), logout({ all: true }))}>
          {busy === 'all' ? <Loader2 className="animate-spin" /> : <MonitorSmartphone />} Log out all devices
        </Button>
      </CardContent>
    </Card>
  )
}

export function ProfilePage() {
  const user = useAuth((s) => s.user)
  if (!user) return null
  return (
    <Page className="max-w-2xl">
      <PageHeader title="Profile & settings" />
      <AvatarCard user={user} />
      <NameCard key={user.name} user={user} />
      {profileExtras.map((Extra, i) => (
        <Extra key={i} />
      ))}
      <PasswordCard />
      <SessionCard />
    </Page>
  )
}
