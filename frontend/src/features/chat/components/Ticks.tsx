import { AlertCircle, Check, CheckCheck, Clock } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { TickStatus } from '../types'

const labels: Record<TickStatus, string> = {
  pending: 'Sending',
  failed: 'Not sent',
  sent: 'Sent',
  delivered: 'Delivered',
  read: 'Read',
}

/**
 * MSG-7 WhatsApp-style delivery status: clock (not on the server yet) → one grey tick
 * (sent) → two grey ticks (delivered to all) → two blue ticks (read by all).
 */
export function Ticks({ status, className }: { status: TickStatus; className?: string }) {
  const common = cn('size-4 shrink-0', className)
  const icon =
    status === 'pending' ? (
      <Clock className={cn(common, 'size-3.5 text-muted-foreground')} />
    ) : status === 'failed' ? (
      <AlertCircle className={cn(common, 'text-destructive')} />
    ) : status === 'sent' ? (
      <Check className={cn(common, 'text-muted-foreground')} />
    ) : (
      <CheckCheck className={cn(common, status === 'read' ? 'text-sky-500' : 'text-muted-foreground')} />
    )
  return (
    <span role="img" aria-label={labels[status]} data-status={status} className="inline-flex">
      {icon}
    </span>
  )
}
