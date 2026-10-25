import { AlertTriangle, Inbox, RefreshCw } from 'lucide-react'
import type { ReactNode } from 'react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { errorMessage } from '@/lib/api'
import { cn } from '@/lib/utils'

export function initials(name?: string | null) {
  if (!name) return '?'
  const parts = name.replace(/^(dr|prof|mr|mrs|ms)\.?\s+/i, '').trim().split(/\s+/)
  return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}

export function UserAvatar({
  user,
  className,
  online,
}: {
  user?: { name?: string | null; avatar_url?: string | null } | null
  className?: string
  online?: boolean
}) {
  return (
    <span className="relative inline-flex shrink-0">
      <Avatar className={className}>
        {user?.avatar_url && <AvatarImage src={user.avatar_url} alt="" />}
        <AvatarFallback className="text-xs font-medium">{initials(user?.name)}</AvatarFallback>
      </Avatar>
      {online !== undefined && (
        <span
          aria-label={online ? 'online' : 'offline'}
          className={cn(
            'absolute right-0 bottom-0 size-3 rounded-full border-2 border-background',
            online ? 'bg-emerald-500' : 'bg-muted-foreground/40',
          )}
        />
      )}
    </span>
  )
}

export function PageHeader({
  title,
  description,
  actions,
  className,
}: {
  title: ReactNode
  description?: ReactNode
  actions?: ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between', className)}>
      <div className="min-w-0">
        <h1 className="truncate text-2xl font-semibold tracking-tight">{title}</h1>
        {description && <p className="mt-1 text-sm text-muted-foreground">{description}</p>}
      </div>
      {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
    </div>
  )
}

export function Page({ children, className, wide }: { children: ReactNode; className?: string; wide?: boolean }) {
  return <div className={cn('mx-auto w-full space-y-5 p-4 md:p-6', wide ? 'max-w-7xl' : 'max-w-5xl', className)}>{children}</div>
}

export function EmptyState({ icon: Icon = Inbox, title, hint, action }: { icon?: typeof Inbox; title: string; hint?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-8 text-center">
      <Icon className="size-8 text-muted-foreground" />
      <p className="font-medium">{title}</p>
      {hint && <p className="max-w-sm text-sm text-muted-foreground">{hint}</p>}
      {action}
    </div>
  )
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <div role="alert" className="flex flex-col items-center justify-center gap-3 rounded-lg border border-destructive/30 p-8 text-center">
      <AlertTriangle className="size-8 text-destructive" />
      <p className="text-sm">{errorMessage(error)}</p>
      {onRetry && (
        <Button variant="outline" size="sm" onClick={onRetry}>
          <RefreshCw /> Try again
        </Button>
      )}
    </div>
  )
}

export function ListSkeleton({ rows = 4, className }: { rows?: number; className?: string }) {
  return (
    <div className={cn('space-y-3', className)} aria-busy="true">
      {Array.from({ length: rows }).map((_, i) => (
        <Skeleton key={i} className="h-14 w-full" />
      ))}
    </div>
  )
}
