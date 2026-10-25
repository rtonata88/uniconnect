import { useQuery } from '@tanstack/react-query'
import { useLiveQuery } from 'dexie-react-hooks'
import { Bell, CheckCheck } from 'lucide-react'
import { useNavigate } from 'react-router'
import { EmptyState, Page, PageHeader } from '@/components/common'
import { Button } from '@/components/ui/button'
import { db } from '@/lib/db'
import { fmtDateTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { markNotificationsRead, refreshNotifications, type AppNotification } from './data'
import { categoryIcon } from './icons'

export function NotificationsPage() {
  const navigate = useNavigate()
  useQuery({ queryKey: ['notifications'], queryFn: refreshNotifications })
  const items = (useLiveQuery(() => db.notifications.orderBy('created_at').reverse().toArray(), []) ?? []) as AppNotification[]
  const unread = items.filter((n) => !n.read_at).length
  return (
    <Page className="max-w-3xl">
      <PageHeader
        title="Notifications"
        description={unread ? `${unread} unread` : 'All caught up'}
        actions={
          unread > 0 && (
            <Button variant="outline" onClick={() => void markNotificationsRead('all')}>
              <CheckCheck /> Mark all read
            </Button>
          )
        }
      />
      {items.length === 0 ? (
        <EmptyState icon={Bell} title="No notifications" hint="Announcements, new assignments, grades and reminders will appear here." />
      ) : (
        <ul className="divide-y rounded-lg border">
          {items.map((n) => {
            const Icon = categoryIcon[n.category] ?? Bell
            return (
              <li key={n.id}>
                <button
                  type="button"
                  onClick={() => {
                    void markNotificationsRead([n.id])
                    navigate(n.url)
                  }}
                  className={cn('flex min-h-16 w-full gap-3 p-3 text-left hover:bg-muted/60', !n.read_at && 'bg-primary/5')}
                >
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">
                    <Icon className="size-4" />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className={cn('block text-sm', !n.read_at && 'font-semibold')}>{n.title}</span>
                    <span className="block text-sm whitespace-pre-wrap text-muted-foreground">{n.body}</span>
                    <span className="block text-xs text-muted-foreground">{fmtDateTime(n.created_at)}</span>
                  </span>
                  {!n.read_at && <span className="mt-2 size-2 shrink-0 rounded-full bg-primary" aria-label="unread" />}
                </button>
              </li>
            )
          })}
        </ul>
      )}
    </Page>
  )
}
