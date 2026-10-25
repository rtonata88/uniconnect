import { GraduationCap } from 'lucide-react'
import { NavLink, Outlet, useLocation } from 'react-router'
import { adminNav, bottomTabs, mainNav, type NavItem, type Role } from '@/app/nav'
import { ThemeToggle } from '@/components/ThemeToggle'
import { Badge } from '@/components/ui/badge'
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuBadge,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarTrigger,
} from '@/components/ui/sidebar'
import { OfflineBanner } from '@/lib/sync/StatusPill'
import { cn } from '@/lib/utils'

export interface LayoutSlots {
  role?: Role
  badges?: Partial<Record<'chats' | 'notifications', number>>
  header?: React.ReactNode
  footer?: React.ReactNode
}

function isActive(pathname: string, to: string) {
  return to === '/' ? pathname === '/' : pathname === to || pathname.startsWith(to + '/')
}

function SidebarNav({ items, label, badges }: { items: NavItem[]; label?: string; badges: LayoutSlots['badges'] }) {
  const { pathname } = useLocation()
  return (
    <SidebarGroup>
      {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
      <SidebarGroupContent>
        <SidebarMenu>
          {items.map((item) => {
            const count = item.badge ? badges?.[item.badge] : undefined
            return (
              <SidebarMenuItem key={item.to}>
                <SidebarMenuButton asChild isActive={isActive(pathname, item.to)} tooltip={item.label}>
                  <NavLink to={item.to}>
                    <item.icon />
                    <span>{item.label}</span>
                  </NavLink>
                </SidebarMenuButton>
                {!!count && <SidebarMenuBadge>{count > 99 ? '99+' : count}</SidebarMenuBadge>}
              </SidebarMenuItem>
            )
          })}
        </SidebarMenu>
      </SidebarGroupContent>
    </SidebarGroup>
  )
}

/** Mobile bottom tab bar with safe-area padding and 44px+ touch targets (MOB-1, MOB-3). */
function BottomTabs({ badges }: { badges: LayoutSlots['badges'] }) {
  const { pathname } = useLocation()
  return (
    <nav
      aria-label="Main"
      className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden"
    >
      <ul className="grid grid-cols-4">
        {bottomTabs.map((item) => {
          const active = isActive(pathname, item.to)
          const count = item.badge ? badges?.[item.badge] : undefined
          return (
            <li key={item.to}>
              <NavLink
                to={item.to}
                className={cn(
                  'relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-xs',
                  active ? 'text-primary font-medium' : 'text-muted-foreground',
                )}
              >
                <item.icon className="size-5" />
                {item.label}
                {!!count && (
                  <Badge className="absolute top-1.5 left-1/2 ml-2 h-4 min-w-4 px-1 text-[10px]">
                    {count > 99 ? '99+' : count}
                  </Badge>
                )}
              </NavLink>
            </li>
          )
        })}
      </ul>
    </nav>
  )
}

export function AppLayout({ role, badges, header, footer }: LayoutSlots) {
  const admin = adminNav.filter((i) => !i.roles || (role && i.roles.includes(role)))
  return (
    <SidebarProvider>
      <Sidebar collapsible="icon" className="hidden md:flex">
        <SidebarHeader>
          <div className="flex items-center gap-2 px-2 py-1.5">
            <GraduationCap className="size-6 shrink-0 text-primary" />
            <span className="truncate text-base font-semibold group-data-[collapsible=icon]:hidden">UniConnect</span>
          </div>
        </SidebarHeader>
        <SidebarContent>
          <SidebarNav items={mainNav} badges={badges} />
          {admin.length > 0 && <SidebarNav items={admin} label="Administration" badges={badges} />}
        </SidebarContent>
        {footer && <SidebarFooter>{footer}</SidebarFooter>}
      </Sidebar>
      <SidebarInset className="min-w-0">
        <header className="sticky top-0 z-30 flex h-14 items-center gap-2 border-b bg-background/95 px-3 pt-[env(safe-area-inset-top)] backdrop-blur md:px-4">
          <SidebarTrigger className="hidden md:inline-flex" />
          <GraduationCap className="size-6 text-primary md:hidden" />
          <div className="flex min-w-0 flex-1 items-center gap-2">{header}</div>
          <ThemeToggle />
        </header>
        <OfflineBanner />
        <main className="flex min-w-0 flex-1 flex-col pb-[calc(4rem+env(safe-area-inset-bottom))] md:pb-0">
          <Outlet />
        </main>
      </SidebarInset>
      <BottomTabs badges={badges} />
    </SidebarProvider>
  )
}
