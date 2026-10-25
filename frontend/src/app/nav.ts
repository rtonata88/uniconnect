import {
  Activity,
  BookOpen,
  Bell,
  LayoutDashboard,
  MessageCircle,
  ScrollText,
  User,
  Users,
  type LucideIcon,
} from 'lucide-react'

export type Role = 'admin' | 'lecturer' | 'student'

export interface NavItem {
  to: string
  label: string
  icon: LucideIcon
  roles?: Role[]
  badge?: 'chats' | 'notifications'
}

/** Sidebar (desktop). */
export const mainNav: NavItem[] = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/courses', label: 'Courses', icon: BookOpen },
  { to: '/chats', label: 'Chats', icon: MessageCircle, badge: 'chats' },
  { to: '/notifications', label: 'Notifications', icon: Bell, badge: 'notifications' },
  { to: '/profile', label: 'Profile', icon: User },
]

export const adminNav: NavItem[] = [
  { to: '/admin/users', label: 'Users', icon: Users, roles: ['admin'] },
  { to: '/admin/courses', label: 'Courses & enrolments', icon: BookOpen, roles: ['admin'] },
  { to: '/admin/system', label: 'System status', icon: Activity, roles: ['admin'] },
  { to: '/admin/audit-logs', label: 'Audit log', icon: ScrollText, roles: ['admin'] },
]

/** Bottom tab bar (mobile, MOB-1). */
export const bottomTabs: NavItem[] = [
  { to: '/courses', label: 'Courses', icon: BookOpen },
  { to: '/chats', label: 'Chats', icon: MessageCircle, badge: 'chats' },
  { to: '/notifications', label: 'Alerts', icon: Bell, badge: 'notifications' },
  { to: '/profile', label: 'Profile', icon: User },
]
