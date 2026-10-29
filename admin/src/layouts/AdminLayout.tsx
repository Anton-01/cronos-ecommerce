import { Boxes, Coins, FolderTree, Images, LayoutDashboard, LogOut, Percent, Receipt, Settings, ShieldAlert, ScrollText, ShieldCheck, SlidersHorizontal, Tags, Ticket, User, Users, Warehouse } from 'lucide-react'
import { Link, NavLink, Outlet } from 'react-router-dom'

import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { useAuth } from '@/features/auth/AuthProvider'
import { useClock } from '@/lib/clock'
import { cn } from '@/lib/utils'

const NAV = [
  { to: '/', label: 'Resumen', icon: LayoutDashboard, permission: null, end: true },
  { to: '/users', label: 'Usuarios', icon: Users, permission: 'users.view', end: false },
  { to: '/roles', label: 'Roles', icon: ShieldCheck, permission: 'roles.view', end: false },
  { to: '/catalog/products', label: 'Productos', icon: Boxes, permission: 'catalog.view', end: false },
  { to: '/catalog/categories', label: 'Categorías', icon: FolderTree, permission: 'catalog.view', end: false },
  { to: '/catalog/attributes', label: 'Atributos', icon: SlidersHorizontal, permission: 'catalog.view', end: false },
  { to: '/catalog/tags', label: 'Etiquetas', icon: Tags, permission: 'catalog.view', end: false },
  { to: '/inventory', label: 'Inventario', icon: Warehouse, permission: 'inventory.view', end: false },
  { to: '/finance/currencies', label: 'Monedas y mercados', icon: Coins, permission: 'pricing.view', end: false },
  { to: '/finance/taxes', label: 'Impuestos', icon: Receipt, permission: 'pricing.view', end: false },
  { to: '/finance/coupons', label: 'Cupones', icon: Ticket, permission: 'promotions.view', end: false },
  { to: '/finance/promotions', label: 'Promociones', icon: Percent, permission: 'promotions.view', end: false },
  { to: '/finance/fraud', label: 'Antifraude', icon: ShieldAlert, permission: 'fraud.view', end: false },
  { to: '/audit', label: 'Auditoría', icon: ScrollText, permission: 'audits.view', end: false },
  { to: '/media', label: 'Medios', icon: Images, permission: 'media.view', end: false },
  { to: '/settings', label: 'Configuración', icon: Settings, permission: 'settings.view', end: false },
] as const

export function AdminLayout() {
  const { user, can, signOut } = useAuth()
  const clock = useClock()
  const name = [user?.profile?.firstName, user?.profile?.lastName].filter(Boolean).join(' ') || user?.email

  return (
    <div className="flex min-h-screen">
      <aside className="hidden w-60 shrink-0 border-r bg-card md:block">
        <div className="flex h-14 items-center border-b px-5 font-semibold">Cronos Admin</div>
        <nav className="space-y-1 p-3" aria-label="Principal">
          {NAV.filter((item) => !item.permission || can(item.permission)).map(({ to, label, icon: Icon, end }) => (
            <NavLink
              key={to}
              to={to}
              end={end}
              className={({ isActive }) =>
                cn(
                  'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground',
                  isActive && 'bg-accent text-accent-foreground',
                )
              }
            >
              <Icon className="size-4" />
              {label}
            </NavLink>
          ))}
        </nav>
      </aside>
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-14 items-center justify-end gap-3 border-b px-6">
          <span className="hidden text-xs text-muted-foreground sm:inline" data-testid="clock" title="Zona horaria según tu IP: todas las fechas se muestran con ella">
            {clock.timezone ? `${clock.country ?? '—'} · ${clock.timezone.replace(/_/g, ' ')} (UTC${clock.utcOffset})` : ''}
          </span>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="max-w-64">
                {user?.avatarUrl && <img src={user.avatarUrl} alt="" className="size-6 rounded-full object-cover" />}
                <span className="truncate">{name}</span>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuLabel>{user?.email}</DropdownMenuLabel>
              <DropdownMenuItem asChild>
                <Link to="/profile">
                  <User className="size-4" /> Mi perfil
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => void signOut()}>
                <LogOut className="size-4" /> Cerrar sesión
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </header>
        <main className="flex-1 p-6">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
