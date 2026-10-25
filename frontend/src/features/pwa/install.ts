import { create } from 'zustand'
import { isIos, isStandalone } from '@/lib/push/push'

interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

/** MOB-2: "Install app" — Chrome/Edge/Android use beforeinstallprompt; iOS shows instructions. */
export const useInstall = create<{ deferred: BeforeInstallPromptEvent | null; installed: boolean }>(() => ({
  deferred: null,
  installed: typeof window !== 'undefined' && isStandalone(),
}))

window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  useInstall.setState({ deferred: e as BeforeInstallPromptEvent })
})
window.addEventListener('appinstalled', () => useInstall.setState({ deferred: null, installed: true }))

export async function promptInstall(): Promise<boolean> {
  const e = useInstall.getState().deferred
  if (!e) return false
  await e.prompt()
  const { outcome } = await e.userChoice
  useInstall.setState({ deferred: null, installed: outcome === 'accepted' })
  return outcome === 'accepted'
}

export const canShowIosSteps = () => isIos() && !isStandalone()
