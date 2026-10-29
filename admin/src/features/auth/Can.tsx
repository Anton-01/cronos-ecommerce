import type { ReactNode } from 'react'

import { useAuth } from './AuthProvider'

/** Renders children only when the signed-in staff member holds the permission. UI hint only: the API enforces it. */
export function Can({ permission, children, fallback = null }: { permission: string; children: ReactNode; fallback?: ReactNode }) {
  const { can } = useAuth()
  return <>{can(permission) ? children : fallback}</>
}
