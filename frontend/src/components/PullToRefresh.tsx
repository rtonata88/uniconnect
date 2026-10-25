import { Loader2, ArrowDown } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import { cn } from '@/lib/utils'

const THRESHOLD = 70

/**
 * MOB-3 pull-to-refresh for lists on touch screens. Works with the page scroll or with
 * an inner scroll container (pass `scrollRef`).
 */
export function PullToRefresh({ onRefresh, children, className, scrollRef }: { onRefresh: () => Promise<unknown>; children: ReactNode; className?: string; scrollRef?: React.RefObject<HTMLElement | null> }) {
  const start = useRef<number | null>(null)
  const [pull, setPull] = useState(0)
  const [busy, setBusy] = useState(false)
  const atTop = () => (scrollRef?.current ? scrollRef.current.scrollTop <= 0 : window.scrollY <= 0)

  return (
    <div
      className={cn('relative', className)}
      onTouchStart={(e) => {
        start.current = atTop() && !busy ? e.touches[0].clientY : null
      }}
      onTouchMove={(e) => {
        if (start.current === null) return
        const dy = e.touches[0].clientY - start.current
        setPull(dy > 0 ? Math.min(dy * 0.5, THRESHOLD * 1.4) : 0)
      }}
      onTouchEnd={async () => {
        const ready = pull >= THRESHOLD
        start.current = null
        setPull(0)
        if (!ready) return
        setBusy(true)
        try {
          await onRefresh()
        } finally {
          setBusy(false)
        }
      }}
    >
      <div
        aria-hidden={!busy && pull === 0}
        className="pointer-events-none flex items-center justify-center overflow-hidden text-xs text-muted-foreground transition-[height]"
        style={{ height: busy ? 40 : pull }}
      >
        {busy ? (
          <Loader2 className="size-4 animate-spin" />
        ) : (
          pull > 8 && (
            <span className="flex items-center gap-1">
              <ArrowDown className={cn('size-4 transition-transform', pull >= THRESHOLD && 'rotate-180')} />
              {pull >= THRESHOLD ? 'Release to refresh' : 'Pull to refresh'}
            </span>
          )
        )}
      </div>
      {children}
    </div>
  )
}
