import { useLiveQuery } from 'dexie-react-hooks'
import { Bell } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { db } from '@/lib/db'
import { fromNow } from '@/lib/format'
import { cn } from '@/lib/utils'
import { markNotificationsRead, type AppNotification } from './data'
import { categoryIcon } from './icons'

export function useUnreadNotifications() {
  return useLiveQuery(() => db.notifications.filter((n) => !n.read_at).count(), []) ?? 0
}

/** NOT-1/NOT-2: bell with live count in the header. */
export function NotificationBell() {
  const unread = useUnreadNotifications()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const recent = (useLiveQuery(() => db.notifications.orderBy('created_at').reverse().limit(8).toArray(), []) ?? []) as AppNotification[]
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="ghost" size="icon" className="relative size-11 md:size-9" aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`}>
          <Bell className="size-5" />
          {unread > 0 && <Badge className="absolute -top-0.5 -right-0.5 h-4 min-w-4 rounded-full px-1 text-[10px]">{unread > 99 ? '99+' : unread}</Badge>}
        </Button>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-80 p-0">
        <div className="flex items-center justify-between border-b px-3 py-2">
          <p className="text-sm font-semibold">Notifications</p>
          {unread > 0 && (
            <button type="button" className="text-xs text-primary hover:underline" onClick={() => void markNotificationsRead('all')}>
              Mark all read
            </button>
          )}
        </div>
        <ul className="max-h-96 overflow-y-auto">
          {recent.length === 0 && <li className="p-4 text-center text-sm text-muted-foreground">You're all caught up.</li>}
          {recent.map((n) => {
            const Icon = categoryIcon[n.category] ?? Bell
            return (
              <li key={n.id}>
                <button
                  type="button"
                  className={cn('flex w-full gap-3 px-3 py-2.5 text-left hover:bg-muted', !n.read_at && 'bg-primary/5')}
                  onClick={() => {
                    void markNotificationsRead([n.id])
                    setOpen(false)
                    navigate(n.url)
                  }}
                >
                  <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium">{n.title}</span>
                    <span className="line-clamp-2 block text-xs text-muted-foreground">{n.body}</span>
                    <span className="block text-[11px] text-muted-foreground">{fromNow(n.created_at)}</span>
                  </span>
                  {!n.read_at && <span className="mt-1.5 size-2 shrink-0 rounded-full bg-primary" aria-label="unread" />}
                </button>
              </li>
            )
          })}
        </ul>
        <Link to="/notifications" onClick={() => setOpen(false)} className="block border-t px-3 py-2 text-center text-sm text-primary hover:bg-muted">
          See all
        </Link>
      </PopoverContent>
    </Popover>
  )
}
