import { Loader2, ShieldAlert } from 'lucide-react'
import type { ReactNode } from 'react'
import { Navigate, Outlet, useLocation } from 'react-router'
import type { Role } from '@/app/nav'
import { useAuth } from './store'

export function FullPageSpinner() {
  return (
    <div className="flex min-h-svh items-center justify-center" role="status" aria-label="Loading">
      <Loader2 className="size-8 animate-spin text-muted-foreground" />
    </div>
  )
}

/** Redirects to /login when signed out. */
export function RequireAuth({ children }: { children?: ReactNode }) {
  const { ready, user } = useAuth()
  const location = useLocation()
  if (!ready) return <FullPageSpinner />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname + location.search }} />
  return children ?? <Outlet />
}

export function Forbidden({ message = 'You do not have access to this page.' }: { message?: string }) {
  return (
    <div className="flex flex-1 flex-col items-center justify-center gap-3 p-8 text-center">
      <ShieldAlert className="size-10 text-muted-foreground" />
      <h1 className="text-xl font-semibold">Access denied</h1>
      <p className="max-w-sm text-muted-foreground">{message}</p>
    </div>
  )
}

export function RequireRole({ roles, children }: { roles: Role[]; children?: ReactNode }) {
  const user = useAuth((s) => s.user)
  if (!user || !roles.includes(user.role)) return <Forbidden />
  return children ?? <Outlet />
}
