import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BellRing, Loader2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldLabel } from '@/components/ui/field'
import { Switch } from '@/components/ui/switch'
import { api, errorMessage } from '@/lib/api'
import { currentSubscription, disablePush, enablePush, isIos, isStandalone, pushSupported } from '@/lib/push/push'

interface Prefs {
  push: Record<string, boolean>
  categories: Record<string, string>
}

/** NOT-3 enable push on this device + NOT-5 per-category preferences. */
export function PushSettingsCard() {
  const qc = useQueryClient()
  const [enabled, setEnabled] = useState<boolean | null>(null)
  const [busy, setBusy] = useState(false)
  const prefs = useQuery({ queryKey: ['notification-preferences'], queryFn: async () => (await api.get<Prefs>('/notification-preferences')).data })
  const save = useMutation({
    mutationFn: async (push: Record<string, boolean>) => (await api.put<Prefs>('/notification-preferences', { push })).data,
    onSuccess: (data) => qc.setQueryData(['notification-preferences'], data),
    onError: (e) => toast.error(errorMessage(e)),
  })

  useEffect(() => {
    void currentSubscription().then((s) => setEnabled(!!s && Notification.permission === 'granted'))
  }, [])

  const toggleDevice = async () => {
    setBusy(true)
    try {
      if (enabled) {
        await disablePush()
        setEnabled(false)
        toast.success('Push notifications turned off on this device')
      } else {
        await enablePush()
        setEnabled(true)
        toast.success('Push notifications are on for this device')
      }
    } catch (e) {
      toast.error(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Notifications</CardTitle>
        <CardDescription>In-app notifications are always on. Choose what is pushed to this device when you are not using the app.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {!pushSupported() ? (
          <p className="text-sm text-muted-foreground">
            {isIos() && !isStandalone() ? 'On iPhone and iPad, add UniConnect to your home screen to receive push notifications.' : 'This browser does not support push notifications.'}
          </p>
        ) : (
          <Button variant={enabled ? 'outline' : 'default'} onClick={toggleDevice} disabled={busy || enabled === null}>
            {busy ? <Loader2 className="animate-spin" /> : <BellRing />} {enabled ? 'Turn off push on this device' : 'Enable push notifications'}
          </Button>
        )}
        {prefs.data && (
          <div className="grid gap-3 sm:grid-cols-2">
            {Object.entries(prefs.data.categories).map(([key, label]) => (
              <Field key={key} orientation="horizontal">
                <Switch id={`pref-${key}`} checked={prefs.data.push[key]} onCheckedChange={(v) => save.mutate({ [key]: v })} />
                <FieldLabel htmlFor={`pref-${key}`}>{label}</FieldLabel>
              </Field>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
