import { Navigate, Outlet, useLocation } from 'react-router-dom'

import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'

import { getEndReason } from '@/lib/session'

import { useAuth } from './AuthProvider'

export function RequireAuth({ permission }: { permission?: string }) {
  const { status, can, signOut } = useAuth()
  const location = useLocation()

  if (status === 'loading') {
    return (
      <div className="p-8" role="status" aria-label="Cargando sesión">
        <Skeleton className="h-8 w-48" />
      </div>
    )
  }

  if (status === 'anonymous') {
    // Only an expired session returns to where the user was; after a deliberate sign-out the next
    // person to log in must not inherit that location.
    const state = getEndReason() === 'signout' ? undefined : { from: location.pathname }
    return <Navigate to="/login" replace state={state} />
  }

  if (!can('admin.access') || (permission && !can(permission))) {
    return (
      <div className="mx-auto mt-24 max-w-md space-y-4 text-center">
        <h1 className="text-xl font-semibold">Sin acceso</h1>
        <p className="text-sm text-muted-foreground">Tu cuenta no tiene permiso para ver esta sección.</p>
        <Button variant="outline" onClick={() => void signOut()}>
          Cerrar sesión
        </Button>
      </div>
    )
  }

  return <Outlet />
}
