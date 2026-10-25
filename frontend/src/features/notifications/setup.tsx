import { toast } from 'sonner'
import { shellSlots } from '@/app/shellSlots'
import { onUserEvent } from '@/features/chat/realtime'
import { profileExtras } from '@/features/profile/extras'
import { router } from '@/app/router'
import { beforeLogout, onAuthChange } from '@/features/auth/store'
import { disablePush, refreshPushRegistration } from '@/lib/push/push'
import { refreshNotifications, storeNotification, type AppNotification } from './data'
import { NotificationBell, useUnreadNotifications } from './NotificationBell'
import { PushSettingsCard } from './PushSettingsCard'

/** NOT-2: live notifications on private-user.{id} → bell + Sonner toast. */
onUserEvent('NotificationCreated', (payload) => {
  const n = payload as AppNotification
  void storeNotification({ ...n, read_at: null, created_at: n.created_at ?? new Date().toISOString() })
  toast(n.title, {
    description: n.body,
    action: n.url ? { label: 'Open', onClick: () => void router.navigate(n.url) } : undefined,
  })
})

onAuthChange((user) => {
  if (!user) return
  void refreshNotifications().catch(() => undefined)
  void refreshPushRegistration()
})
beforeLogout(disablePush)

shellSlots.headerWidgets.push(NotificationBell)
const previous = shellSlots.useBadges
shellSlots.useBadges = () => ({ ...previous(), notifications: useUnreadNotifications() })
profileExtras.push(PushSettingsCard)
