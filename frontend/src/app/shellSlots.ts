import type { ComponentType } from 'react'

/**
 * Hooks for features to extend the shell without circular imports: header widgets
 * (sync status pill, notification bell) and nav badge counts (unread chats/notifications).
 */
export const shellSlots: {
  headerWidgets: ComponentType[]
  useBadges: () => Partial<Record<'chats' | 'notifications', number>>
} = {
  headerWidgets: [],
  useBadges: () => ({}),
}
