import { format, formatDistanceToNowStrict, isPast, isThisYear, isToday, isYesterday } from 'date-fns'

export const fmtDateTime = (iso: string) => format(new Date(iso), isThisYear(new Date(iso)) ? 'd MMM, HH:mm' : 'd MMM yyyy, HH:mm')
export const fmtDate = (iso: string) => format(new Date(iso), 'EEE d MMM yyyy')
export const fmtTime = (iso: string) => format(new Date(iso), 'HH:mm')
export const fromNow = (iso: string) => formatDistanceToNowStrict(new Date(iso), { addSuffix: true })

/** Chat list style: time today, "Yesterday", weekday/date otherwise. */
export function fmtShort(iso: string) {
  const d = new Date(iso)
  if (isToday(d)) return format(d, 'HH:mm')
  if (isYesterday(d)) return 'Yesterday'
  return format(d, isThisYear(d) ? 'd MMM' : 'd/M/yy')
}

export function dueLabel(dueIso: string) {
  const d = new Date(dueIso)
  return isPast(d) ? `Closed ${fromNow(dueIso)}` : `Due ${fromNow(dueIso)}`
}

/** For <input type="datetime-local"> */
export const toLocalInput = (iso: string) => format(new Date(iso), "yyyy-MM-dd'T'HH:mm")
