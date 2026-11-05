import { useQuery } from '@apollo/client/react'
import { Bell, ClipboardCheck, ClipboardList, History, MapPin, QrCode, ScanLine, ShieldCheck, SlidersHorizontal, Tag, type LucideIcon } from 'lucide-react'
import { NavLink, Outlet } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { useAuth } from '@/features/auth/AuthProvider'
import { NativeSelect } from '@/features/catalog/shared'
import { WhAlertInboxDocument } from '@/graphql/generated'
import { cn } from '@/lib/utils'

import { useWarehouse } from './useWarehouse'

interface Item {
  to: string
  label: string
  icon: LucideIcon
  permission: string
  /** Shown in the bottom bar of a phone. */
  primary?: boolean
}

export const WAREHOUSE_NAV: Item[] = [
  { to: '/warehouse', label: 'Escanear', icon: ScanLine, permission: 'warehouse.view', primary: true },
  { to: '/warehouse/pick', label: 'Surtir', icon: ClipboardList, permission: 'warehouse.view', primary: true },
  { to: '/warehouse/count', label: 'Contar', icon: ClipboardCheck, permission: 'warehouse.view', primary: true },
  { to: '/warehouse/alerts', label: 'Alertas', icon: Bell, permission: 'warehouse.view', primary: true },
  { to: '/warehouse/locations', label: 'Ubicaciones', icon: MapPin, permission: 'warehouse.view' },
  { to: '/warehouse/approvals', label: 'Aprobaciones', icon: ShieldCheck, permission: 'warehouse.approve' },
  { to: '/warehouse/trail', label: 'Trazabilidad', icon: History, permission: 'warehouse.audit' },
  { to: '/warehouse/rules', label: 'Reglas', icon: SlidersHorizontal, permission: 'warehouse.approve' },
  { to: '/warehouse/labels', label: 'Etiquetas', icon: Tag, permission: 'warehouse.view' },
]

/** The warehouse section: a phone-first frame with a bottom bar for the daily jobs and a scroll of the rest. */
export function WarehouseShell() {
  const { can } = useAuth()
  const { warehouses, current, choose } = useWarehouse()
  const { data } = useQuery(WhAlertInboxDocument, { variables: { unreadOnly: true, first: 1 }, pollInterval: 60_000 })
  const unread = data?.whAlertInbox.unread ?? 0
  const items = WAREHOUSE_NAV.filter((i) => can(i.permission))

  const link = (i: Item, compact: boolean) => (
    <NavLink
      key={i.to}
      to={i.to}
      end={i.to === '/warehouse'}
      className={({ isActive }) =>
        cn(
          'relative flex items-center gap-2 rounded-md text-sm font-medium transition-colors',
          compact ? 'min-h-14 flex-1 flex-col justify-center px-1 text-xs' : 'min-h-10 px-3 whitespace-nowrap',
          isActive ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
        )
      }
    >
      <i.icon className={compact ? 'size-5' : 'size-4'} aria-hidden />
      {i.label}
      {i.to === '/warehouse/alerts' && unread > 0 && (
        <Badge variant="destructive" className="absolute right-1 top-1 px-1.5" aria-label={`${unread} sin leer`}>
          {unread}
        </Badge>
      )}
    </NavLink>
  )

  return (
    <div className="mx-auto max-w-6xl space-y-4 pb-24 md:pb-0">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <QrCode className="size-5 text-primary" aria-hidden />
          <h1 className="text-xl font-semibold tracking-tight">Almacén</h1>
        </div>
        {warehouses.length > 1 && current && (
          <NativeSelect aria-label="Almacén de trabajo" value={current.id} onChange={(e) => choose(e.target.value)} className="w-auto min-w-44">
            {warehouses.map((w) => (
              <option key={w.id} value={w.id}>
                {w.name} ({w.code})
              </option>
            ))}
          </NativeSelect>
        )}
      </div>

      <nav aria-label="Almacén" className="hidden flex-wrap gap-1 border-b pb-2 md:flex">
        {items.map((i) => link(i, false))}
      </nav>

      <Outlet />

      <nav aria-label="Acciones del almacén" className="fixed inset-x-0 bottom-0 z-20 flex gap-1 border-t bg-card p-1 md:hidden">
        {items.filter((i) => i.primary).map((i) => link(i, true))}
        <NavLink to="/warehouse/more" className={({ isActive }) => cn('flex min-h-14 flex-1 flex-col items-center justify-center rounded-md px-1 text-xs font-medium', isActive ? 'bg-primary text-primary-foreground' : 'text-muted-foreground')}>
          <SlidersHorizontal className="size-5" aria-hidden />
          Más
        </NavLink>
      </nav>
    </div>
  )
}

/** The rest of the sections, as big targets (phone). */
export function MorePage() {
  const { can } = useAuth()
  const rest = WAREHOUSE_NAV.filter((i) => !i.primary && can(i.permission))
  return (
    <ul className="grid gap-2 sm:grid-cols-2">
      {rest.map((i) => (
        <li key={i.to}>
          <NavLink to={i.to} className="flex min-h-16 items-center gap-3 rounded-lg border bg-card p-4 text-base font-medium hover:bg-accent">
            <i.icon className="size-6 text-primary" aria-hidden />
            {i.label}
          </NavLink>
        </li>
      ))}
    </ul>
  )
}
