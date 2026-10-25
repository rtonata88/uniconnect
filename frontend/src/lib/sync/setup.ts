import { queryClient } from '@/app/providers'
import { shellSlots } from '@/app/shellSlots'
import { onAuthChange } from '@/features/auth/store'
import { onReconnect, resubscribe } from '@/features/chat/realtime'
import { onBackOnline, startConnectivity } from './connectivity'
import { onSynced, runSync, startPeriodicSync, stopPeriodicSync } from './engine'
import { processOutbox } from './outbox'
import { SyncStatusPill } from './StatusPill'

/**
 * Sync triggers (PRD 9.3): app start, connection back (online event or health probe),
 * Reverb reconnect, and every 60 s while online. After a sync the screens refresh from
 * the local database and realtime subscriptions follow the conversation list.
 */
onAuthChange((user) => {
  if (user) {
    startConnectivity()
    void runSync()
    startPeriodicSync()
  } else {
    stopPeriodicSync()
  }
})

onBackOnline(() => {
  void processOutbox()
  void runSync()
})
onReconnect(() => void runSync())
onSynced(() => {
  void resubscribe()
  void queryClient.invalidateQueries({ predicate: (q) => ['courses', 'assignments', 'notifications'].includes(String(q.queryKey[0])) })
})

shellSlots.headerWidgets.unshift(SyncStatusPill)
