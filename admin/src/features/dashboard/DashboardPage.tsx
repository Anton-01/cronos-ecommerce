import { useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { NativeSelect } from '@/features/catalog/shared'
import { statusLabel } from '@/features/payments/phrase'
import { orderLabel } from '@/features/orders/status'
import { AdminDashboardDocument, AdminMarketsDocument, type AdminDashboardQuery, type PeriodPreset } from '@/graphql/generated'

import { ExportsPanel } from './ExportsPanel'
import { ATTENTION_LABEL, bars, change, money } from './format'

type Dashboard = AdminDashboardQuery['adminDashboard']

const PERIODS: { value: PeriodPreset; label: string }[] = [
  { value: 'TODAY', label: 'Hoy' },
  { value: 'YESTERDAY', label: 'Ayer' },
  { value: 'LAST_7_DAYS', label: 'Últimos 7 días' },
  { value: 'LAST_30_DAYS', label: 'Últimos 30 días' },
  { value: 'THIS_MONTH', label: 'Este mes' },
  { value: 'LAST_MONTH', label: 'Mes anterior' },
]

const SEVERITY_CLASS: Record<string, string> = {
  critical: 'border-destructive/40 bg-destructive/10 text-destructive',
  warning: 'border-amber-400/50 bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
  info: 'border-border bg-muted/50 text-foreground',
}

export function DashboardPage() {
  const { user, can } = useAuth()
  const canReport = can('reports.view')

  return (
    <div className="max-w-6xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Resumen</h1>
        <p className="text-sm text-muted-foreground">Bienvenido, {user?.profile?.firstName ?? user?.email}.</p>
      </div>
      {canReport ? (
        <Reports />
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Tus roles</CardTitle>
            <CardDescription>Definen lo que puedes hacer en el panel.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {user?.roles.map((role) => (
              <Badge key={role} variant="secondary">
                {role}
              </Badge>
            ))}
          </CardContent>
        </Card>
      )}
      {can('reports.export') && <ExportsPanel />}
    </div>
  )
}

function Reports() {
  const [period, setPeriod] = useState<PeriodPreset>('LAST_7_DAYS')
  const [market, setMarket] = useState('')
  const { data: markets } = useQuery(AdminMarketsDocument)
  const { data, loading, error } = useQuery(AdminDashboardDocument, { variables: { period, market: market || null }, fetchPolicy: 'cache-and-network', pollInterval: 60_000 })
  const d = data?.adminDashboard

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        <NativeSelect aria-label="Periodo" className="w-48" value={period} onChange={(e) => setPeriod(e.target.value as PeriodPreset)}>
          {PERIODS.map((p) => (
            <option key={p.value} value={p.value}>
              {p.label}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Tienda" className="w-48" value={market} onChange={(e) => setMarket(e.target.value)}>
          <option value="">Todas las tiendas</option>
          {markets?.adminMarkets.map((m) => (
            <option key={m.id} value={m.code}>
              {m.name}
            </option>
          ))}
        </NativeSelect>
        {d && <span className="text-xs text-muted-foreground">Días contados en tu zona horaria ({d.period.timezone}). Cada tienda en su propia moneda.</span>}
      </div>

      {error && <p role="alert" className="text-sm text-destructive">No se pudo cargar el resumen.</p>}
      {loading && !d && <Skeleton className="h-64 w-full" />}
      {d && <Content d={d} />}
    </div>
  )
}

function Content({ d }: { d: Dashboard }) {
  const marketsWithSales = d.kpis.map((k) => k.market)
  return (
    <>
      {d.attention.length > 0 && (
        <section aria-label="Requiere atención" className="space-y-2">
          <h2 className="font-medium">Requiere atención</h2>
          <ul className="grid gap-2 md:grid-cols-2">
            {d.attention.map((a) => {
              const info = ATTENTION_LABEL[a.key]
              return (
                <li key={a.key}>
                  <Link to={info?.to ?? '/'} className={`flex items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:opacity-90 ${SEVERITY_CLASS[a.severity] ?? SEVERITY_CLASS.info}`}>
                    <span className="min-w-8 text-lg font-semibold tabular-nums">{a.count}</span>
                    <span>{info?.text ?? a.key}</span>
                  </Link>
                </li>
              )
            })}
          </ul>
        </section>
      )}

      {d.kpis.length === 0 && <p className="rounded-lg border p-4 text-sm text-muted-foreground">Sin ventas en este periodo.</p>}
      {d.kpis.map((k) => (
        <section key={k.market} aria-label={`Ventas ${k.market}`} className="space-y-3">
          <h2 className="font-medium">
            Tienda {k.market} <span className="text-sm font-normal text-muted-foreground">({k.currency})</span>
          </h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi label="Ventas netas" value={money(k.netRevenue, k.currency)} delta={k.revenueChange} hint={`Cobrado ${money(k.revenue, k.currency)} − reembolsos ${money(k.refunded, k.currency)}`} />
            <Kpi label="Pedidos" value={String(k.orders)} delta={k.ordersChange} hint={`Antes: ${k.previousOrders}`} />
            <Kpi label="Ticket promedio" value={money(k.averageOrder, k.currency)} hint={`Sin impuestos: ${money(k.netOfTax, k.currency)}`} />
            <Kpi label="Impuestos / envío" value={`${money(k.tax, k.currency)} / ${money(k.shipping, k.currency)}`} hint={`Descuentos: ${money(k.discounts, k.currency)}`} />
          </div>
          <Chart d={d} market={k.market} currency={k.currency} />
        </section>
      ))}

      <div className="grid gap-6 lg:grid-cols-2">
        <section aria-label="Más vendidos" className="space-y-2">
          <h2 className="font-medium">Más vendidos</h2>
          <ul className="divide-y rounded-xl border bg-card">
            {d.topProducts.map((t, i) => (
              <li key={`${t.market}-${t.productId ?? t.name}-${t.unit}-${i}`} className="flex items-center gap-3 px-3 py-2 text-sm">
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{t.name}</span>
                  <span className="block text-xs text-muted-foreground">
                    {t.sku ?? 'sin SKU'} · {t.quantity} {t.unit} · {t.orders} pedidos{marketsWithSales.length > 1 && ` · ${t.market}`}
                  </span>
                </span>
                <span className="tabular-nums">{money(t.netRevenue, t.currency)}</span>
              </li>
            ))}
            {d.topProducts.length === 0 && <li className="py-6 text-center text-sm text-muted-foreground">Sin ventas.</li>}
          </ul>
        </section>

        <section aria-label="Embudo de compra" className="space-y-2">
          <h2 className="font-medium">Embudo de compra</h2>
          <ul className="divide-y rounded-xl border bg-card text-sm">
            {d.funnel.map((f) => (
              <li key={f.market} className="space-y-1 px-3 py-2">
                <div className="flex items-center justify-between">
                  <span className="font-medium">Tienda {f.market}</span>
                  <span className="text-lg font-semibold tabular-nums">{f.conversion.toLocaleString('es-MX', { maximumFractionDigits: 1 })} %</span>
                </div>
                <p className="text-xs text-muted-foreground">
                  {f.started} iniciados · {f.paid} pagados · {f.open} abiertos · {f.expired} vencidos · {f.cancelled} cancelados{f.needsReview > 0 && ` · ${f.needsReview} por revisar`}
                </p>
              </li>
            ))}
            {d.funnel.length === 0 && <li className="py-6 text-center text-sm text-muted-foreground">Nadie inició un pago.</li>}
          </ul>
          <p className="text-xs text-muted-foreground">Clientes nuevos en el periodo: {d.newCustomers}</p>
        </section>

        <section aria-label="Pagos" className="space-y-2">
          <h2 className="font-medium">Pagos por proveedor</h2>
          <ul className="divide-y rounded-xl border bg-card text-sm">
            {d.payments.map((p) => (
              <li key={`${p.provider}-${p.mode}-${p.currency}`} className="flex flex-wrap items-center gap-2 px-3 py-2">
                <span className="min-w-0 flex-1">
                  <span className="font-medium">{p.provider}</span> <Badge variant={p.mode === 'LIVE' ? 'destructive' : 'secondary'}>{p.mode === 'LIVE' ? 'Producción' : 'Sandbox'}</Badge>
                  <span className="block text-xs text-muted-foreground">
                    {p.created} intentos · {p.captured} cobrados · {p.failed} fallidos · {p.cancelled} cancelados
                  </span>
                </span>
                <span className="text-right tabular-nums">
                  {money(p.capturedAmount, p.currency)}
                  <span className="block text-xs text-muted-foreground">{p.successRate === null || p.successRate === undefined ? 'sin cierres' : `${p.successRate} % éxito`}</span>
                </span>
              </li>
            ))}
            {d.payments.length === 0 && <li className="py-6 text-center text-sm text-muted-foreground">Sin pagos.</li>}
          </ul>
        </section>

        <section aria-label="Pedidos por estado" className="space-y-2">
          <h2 className="font-medium">Pedidos creados por estado</h2>
          <ul className="flex flex-wrap gap-2">
            {d.ordersByStatus.map((s) => (
              <li key={s.status}>
                <Badge variant="outline">
                  {orderLabel(s.status) || statusLabel(s.status)}: {s.orders}
                </Badge>
              </li>
            ))}
            {d.ordersByStatus.length === 0 && <li className="text-sm text-muted-foreground">Sin pedidos.</li>}
          </ul>
        </section>
      </div>
    </>
  )
}

function Kpi({ label, value, delta, hint }: { label: string; value: string; delta?: number | null; hint?: string }) {
  const c = change(delta)
  const tone = { up: 'text-emerald-600 dark:text-emerald-400', down: 'text-destructive', flat: 'text-muted-foreground', none: 'text-muted-foreground' }[c.tone]
  return (
    <div className="rounded-xl border bg-card p-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="text-xl font-semibold tabular-nums">{value}</div>
      <div className="flex items-center justify-between text-xs">
        <span className="truncate text-muted-foreground">{hint}</span>
        {delta !== undefined && <span className={tone} aria-label={`Cambio frente al periodo anterior: ${c.text}`}>{c.text}</span>}
      </div>
    </div>
  )
}

function Chart({ d, market, currency }: { d: Dashboard; market: string; currency: string }) {
  const rows = bars(d.daily.filter((r) => r.market === market))
  if (rows.length === 0) return null
  return (
    <figure className="rounded-xl border bg-card p-3">
      <figcaption className="mb-2 text-xs text-muted-foreground">Ventas por día ({currency})</figcaption>
      <div className="flex h-32 items-end gap-1" role="img" aria-label={`Ventas por día de la tienda ${market}: ${rows.map((r) => `${r.day} ${r.revenue.toFixed(2)}`).join(', ')}`}>
        {rows.map((r) => (
          <div key={r.day} className="group relative flex h-full flex-1 items-end" title={`${r.day}: ${money(String(r.revenue), currency)} · ${r.orders} pedidos`}>
            <div className="w-full rounded-t bg-primary/80 group-hover:bg-primary" style={{ height: `${r.height}%` }} />
          </div>
        ))}
      </div>
      <div className="mt-1 flex justify-between text-[10px] text-muted-foreground">
        <span>{rows[0]!.day}</span>
        <span>{rows[rows.length - 1]!.day}</span>
      </div>
    </figure>
  )
}
