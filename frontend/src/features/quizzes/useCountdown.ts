import { useEffect, useRef, useState } from 'react'

/**
 * Countdown to a server deadline (LMS-9). The offset between server and device clock
 * is measured once from `serverNow`, so a wrong phone clock does not change the time
 * limit. Calls `onExpire` exactly once when time runs out.
 */
export function useCountdown(deadline: string | null | undefined, serverNow: string | undefined, onExpire: () => void) {
  const [offset] = useState(() => (serverNow ? new Date(serverNow).getTime() - Date.now() : 0))
  const end = deadline ? new Date(deadline).getTime() : null
  const remaining = () => (end === null ? null : Math.max(0, end - (Date.now() + offset)))
  const [left, setLeft] = useState<number | null>(remaining)
  const fired = useRef(false)
  const cb = useRef(onExpire)
  cb.current = onExpire

  useEffect(() => {
    if (end === null) return
    const tick = () => {
      const r = Math.max(0, end - (Date.now() + offset))
      setLeft(r)
      if (r === 0 && !fired.current) {
        fired.current = true
        cb.current()
      }
    }
    tick()
    const t = setInterval(tick, 250)
    return () => clearInterval(t)
  }, [end, offset])

  return left
}

export function formatClock(ms: number) {
  const total = Math.ceil(ms / 1000)
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const mm = String(m).padStart(h ? 2 : 1, '0')
  return `${h ? h + ':' : ''}${mm}:${String(s).padStart(2, '0')}`
}
