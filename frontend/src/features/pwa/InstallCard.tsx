import { CheckCircle2, Download, Share, SquarePlus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { canShowIosSteps, promptInstall, useInstall } from './install'

/** MOB-2 install button, with Add-to-Home-Screen steps on iPhone/iPad. */
export function InstallCard() {
  const { deferred, installed } = useInstall()
  return (
    <Card>
      <CardHeader>
        <CardTitle>Install app</CardTitle>
        <CardDescription>Get UniConnect on your home screen: it opens like an app, works offline and receives notifications.</CardDescription>
      </CardHeader>
      <CardContent>
        {installed ? (
          <p className="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-400">
            <CheckCircle2 className="size-4" /> Installed on this device.
          </p>
        ) : deferred ? (
          <Button size="lg" onClick={() => void promptInstall()}>
            <Download /> Install app
          </Button>
        ) : canShowIosSteps() ? (
          <ol className="list-decimal space-y-2 pl-5 text-sm">
            <li>
              Tap <Share className="inline size-4" aria-label="Share" /> <strong>Share</strong> in Safari's toolbar.
            </li>
            <li>
              Choose <SquarePlus className="inline size-4" aria-label="Add" /> <strong>Add to Home Screen</strong>, then <strong>Add</strong>.
            </li>
            <li>Open UniConnect from your home screen and enable notifications in Profile.</li>
          </ol>
        ) : (
          <p className="text-sm text-muted-foreground">
            Use your browser's menu (⋮ or the install icon in the address bar) and choose <strong>Install UniConnect</strong>. On Android Chrome: menu → <strong>Install app</strong>.
          </p>
        )}
      </CardContent>
    </Card>
  )
}
