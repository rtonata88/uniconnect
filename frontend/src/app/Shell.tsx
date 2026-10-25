import { LogOut, User as UserIcon } from 'lucide-react'
import { Link } from 'react-router'
import { AppLayout } from '@/app/layouts/AppLayout'
import { UserAvatar } from '@/components/common'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/store'
import { shellSlots } from './shellSlots'

function UserMenu() {
  const { user, logout } = useAuth()
  if (!user) return null
  return (
    <DropdownMenu>
      <DropdownMenuTrigger className="rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" aria-label="Account menu">
        <UserAvatar user={user} className="size-9" />
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="font-normal">
          <p className="truncate font-medium">{user.name}</p>
          <p className="truncate text-xs text-muted-foreground capitalize">{user.role}</p>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem asChild>
          <Link to="/profile">
            <UserIcon /> Profile & settings
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem onClick={() => logout()}>
          <LogOut /> Log out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

/** Authenticated app chrome: layout + header widgets contributed by features. */
export function Shell() {
  const user = useAuth((s) => s.user)
  const badges = shellSlots.useBadges()
  return (
    <AppLayout
      role={user?.role}
      badges={badges}
      header={
        <>
          <div className="flex-1" />
          {shellSlots.headerWidgets.map((W, i) => (
            <W key={i} />
          ))}
          <UserMenu />
        </>
      }
    />
  )
}
