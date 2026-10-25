import { Award, Bell, CalendarClock, ClipboardList, Megaphone, MessageCircle, Timer, Users } from 'lucide-react'
import type { AppNotification } from './data'

export const categoryIcon: Record<AppNotification['category'], typeof Bell> = {
  announcements: Megaphone,
  assignments: ClipboardList,
  quizzes: Timer,
  grades: Award,
  reminders: CalendarClock,
  groups: Users,
  messages: MessageCircle,
}
