import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import {
  AdminOrderDocument,
  AdminOrdersDocument,
  CancelOrderDocument,
  CreateShipmentDocument,
  MarkShipmentDeliveredDocument,
  TransitionOrderDocument,
  type AdminOrderQuery,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { fulfillmentLabel, MOVE_LABEL, orderLabel, orderNeedsAttention, paymentLabel, shipmentLabel } from './status'

type Order = NonNullable<AdminOrderQuery['adminOrder']>

const PAGE = 25
const CARRIERS = ['estafeta', 'dhl', 'fedex', 'ups', 'usps']

export function OrdersPage() {
  const clock = useClock()
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const [status, setStatus] = useState('')
  const [paymentStatus, setPaymentStatus] = useState('')
  const [offset, setOffset] = useState(0)
  const [open, setOpen] = useState<string | null>(null)
  const { data, loading } = useQuery(AdminOrdersDocument, {
    variables: { search: debounced || null, status: status || null, paymentStatus: paymentStatus || null, first: PAGE, offset },
    fetchPolicy: 'cache-and-network',
  })
  const page = data?.adminOrders

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Pedidos" description="Cada pedido guarda lo que el cliente vio al comprar. El historial de movimientos no se edita." />
      <div className="flex flex-wrap items-center gap-2">
        <Input className="w-64" type="search" aria-label="Buscar pedidos" placeholder="Número o correo…" value={search} onChange={(e) => { setSearch(e.target.value); setOffset(0) }} />
        <NativeSelect aria-label="Estado del pedido" className="w-48" value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0) }}>
          <option value="">Todos los estados</option>
          {['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'].map((s) => (
            <option key={s} value={s}>
              {orderLabel(s)}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Estado del pago" className="w-44" value={paymentStatus} onChange={(e) => { setPaymentStatus(e.target.value); setOffset(0) }}>
          <option value="">Todos los pagos</option>
          {['unpaid', 'paid', 'partially_refunded', 'refunded', 'disputed'].map((s) => (
            <option key={s} value={s}>
              {paymentLabel(s)}
            </option>
          ))}
        </NativeSelect>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.rows.map((o) => (
          <li key={o.id}>
            <button type="button" className="flex w-full flex-wrap items-center gap-3 px-3 py-2.5 text-left hover:bg-muted/50" onClick={() => setOpen(o.id)}>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                  {o.number} · {o.total} {o.currency}
                  {o.type === 'custom' && <span className="ml-2 text-xs font-normal text-muted-foreground">personalizado</span>}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                  {o.email} · {clock.formatDateTime(o.placedAt ?? o.createdAt)}
                  {o.country && ` · ${o.country}`}
                </span>
              </span>
              <Badge variant={orderNeedsAttention(o) ? 'destructive' : 'outline'}>{paymentLabel(o.paymentStatus)}</Badge>
              <Badge variant="secondary">{orderLabel(o.status)}</Badge>
            </button>
          </li>
        ))}
        {page && page.rows.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin pedidos con esos filtros.</li>}
      </ul>
      {page && page.total > PAGE && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            {offset + 1}–{Math.min(offset + PAGE, page.total)} de {page.total}
          </span>
          <span className="flex gap-2">
            <Button size="sm" variant="outline" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>
              Anterior
            </Button>
            <Button size="sm" variant="outline" disabled={offset + PAGE >= page.total} onClick={() => setOffset(offset + PAGE)}>
              Siguiente
            </Button>
          </span>
        </div>
      )}
      <OrderDialog id={open} onClose={() => setOpen(null)} />
    </div>
  )
}

function OrderDialog({ id, onClose }: { id: string | null; onClose: () => void }) {
  const { data } = useQuery(AdminOrderDocument, { variables: { id: id ?? '' }, skip: id === null, fetchPolicy: 'cache-and-network' })
  const o = data?.adminOrder
  return (
    <Dialog open={id !== null} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Pedido {o?.number}</DialogTitle>
          <DialogDescription>{o && `${o.email} · mercado ${o.marketCode}`}</DialogDescription>
        </DialogHeader>
        {!o && <Skeleton className="h-40 w-full" />}
        {o && <OrderDetail order={o} />}
      </DialogContent>
    </Dialog>
  )
}

function OrderDetail({ order: o }: { order: Order }) {
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const canManage = can('orders.manage')
  const refetch = { refetchQueries: [AdminOrderDocument, AdminOrdersDocument] }
  const [transition] = useMutation(TransitionOrderDocument, refetch)
  const [cancel, { loading: cancelling }] = useMutation(CancelOrderDocument, refetch)
  const [markDelivered] = useMutation(MarkShipmentDeliveredDocument, refetch)
  const [showShip, setShowShip] = useState(false)
  const [showCancel, setShowCancel] = useState(false)
  const [reason, setReason] = useState('')
  const [refund, setRefund] = useState(true)
  const canShip = canManage && o.status === 'processing' && ['paid', 'partially_refunded'].includes(o.paymentStatus) && o.fulfillmentStatus !== 'shipped'
  const canCancel = can('orders.cancel') && ['pending', 'processing'].includes(o.status)

  const doCancel = async () => {
    try {
      const r = await cancel({ variables: { id: o.id, reason: reason.trim(), refund } })
      const err = r.data?.cancelOrder.refundError
      if (err) toast.warning(`Pedido cancelado, pero el reembolso quedó pendiente: ${err}. Reintenta desde Pagos.`)
      else toast.success('Pedido cancelado; el stock volvió al inventario.')
      setShowCancel(false)
    } catch (e) {
      await run(() => Promise.reject(e), '')
    }
  }

  return (
    <div className="space-y-4 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <Badge variant="secondary">{orderLabel(o.status)}</Badge>
        <Badge variant={orderNeedsAttention(o) ? 'destructive' : 'outline'}>{paymentLabel(o.paymentStatus)}</Badge>
        <Badge variant="outline">{fulfillmentLabel(o.fulfillmentStatus)}</Badge>
        {o.fraudDecision !== 'allow' && <Badge variant="destructive">Antifraude: {o.fraudDecision} ({o.fraudScore})</Badge>}
        <span className="text-muted-foreground">
          {o.ip ?? '—'} · {o.country ?? '—'} · {o.timezone ?? '—'}
        </span>
      </div>

      <table className="w-full">
        <thead>
          <tr className="text-left text-xs text-muted-foreground">
            <th className="py-1">Producto</th>
            <th>Cant.</th>
            <th className="text-right">Precio</th>
            <th className="text-right">Total</th>
          </tr>
        </thead>
        <tbody>
          {o.items.map((i, n) => (
            <tr key={n} className="border-t">
              <td className="py-1.5">
                {i.name}
                {i.sku && <span className="block text-xs text-muted-foreground">{i.sku}</span>}
              </td>
              <td>
                {i.quantity} {i.unit}
              </td>
              <td className="text-right tabular-nums">{i.unitPrice}</td>
              <td className="text-right tabular-nums">{i.total}</td>
            </tr>
          ))}
        </tbody>
        <tfoot className="text-right tabular-nums">
          <tr className="border-t">
            <td colSpan={3}>Subtotal</td>
            <td>{o.subtotal}</td>
          </tr>
          {o.discount !== '0.00' && (
            <tr>
              <td colSpan={3}>Descuentos {o.couponCode && `(${o.couponCode})`}</td>
              <td>−{o.discount}</td>
            </tr>
          )}
          <tr>
            <td colSpan={3}>Envío</td>
            <td>{o.shipping}</td>
          </tr>
          <tr>
            <td colSpan={3}>{o.taxIncluded ? 'IVA incluido' : 'Impuestos'}</td>
            <td>{o.tax}</td>
          </tr>
          <tr className="font-semibold">
            <td colSpan={3}>Total ({o.currency})</td>
            <td>{o.total}</td>
          </tr>
          {o.refunded !== '0.00' && (
            <tr>
              <td colSpan={3}>Reembolsado</td>
              <td>{o.refunded}</td>
            </tr>
          )}
        </tfoot>
      </table>

      {o.shippingAddress != null && (
        <p className="rounded-md bg-muted/50 p-2 text-xs">
          <strong>Enviar a:</strong> {formatAddress(o.shippingAddress as Record<string, string | null>)}
        </p>
      )}

      <section aria-label="Envíos" className="space-y-2">
        <h3 className="font-medium">Envíos</h3>
        {o.shipments.length === 0 && <p className="text-xs text-muted-foreground">Aún no hay envíos.</p>}
        {o.shipments.map((s) => (
          <div key={s.id} className="rounded-lg border p-2">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">{s.carrier.toUpperCase()}</span>
              {s.trackingNumber && (s.trackingUrl ? <a className="underline" href={s.trackingUrl} target="_blank" rel="noreferrer">{s.trackingNumber}</a> : <span>{s.trackingNumber}</span>)}
              <Badge variant={s.status === 'exception' ? 'destructive' : 'outline'}>{shipmentLabel(s.status)}</Badge>
              <span className="flex-1" />
              {canManage && s.status !== 'delivered' && s.trackingNumber && (
                <Button size="sm" variant="ghost" onClick={() => void run(() => markDelivered({ variables: { id: s.id } }), 'Entrega confirmada.')}>
                  Confirmar entrega
                </Button>
              )}
            </div>
            <p className="text-xs text-muted-foreground">{s.items.map((i) => `${i.quantity} × ${i.name}`).join(' · ')}</p>
            <ol className="mt-1 space-y-0.5 border-l pl-3 text-xs">
              {s.events.map((e, n) => (
                <li key={n}>
                  {shipmentLabel(e.status)} {e.description && `· ${e.description}`} {e.location && `· ${e.location}`}
                  <span className="text-muted-foreground"> · {clock.formatDateTime(e.at)}</span>
                </li>
              ))}
            </ol>
          </div>
        ))}
        {canShip && !showShip && (
          <Button size="sm" variant="outline" onClick={() => setShowShip(true)}>
            Crear envío
          </Button>
        )}
        {showShip && <ShipmentForm order={o} onDone={() => setShowShip(false)} />}
      </section>

      <section aria-label="Historial" className="space-y-1">
        <h3 className="font-medium">Historial</h3>
        <ol className="space-y-0.5 border-l pl-3 text-xs">
          {o.history.map((h) => (
            <li key={h.id}>
              <span className="font-medium">{orderLabel(h.status)}</span> · {paymentLabel(h.paymentStatus)}
              {h.note && ` · ${h.note}`}
              {h.notifyCustomer && <span className="text-muted-foreground"> · se avisó al cliente</span>}
              <span className="text-muted-foreground"> · {clock.formatDateTime(h.createdAt)}{h.country && ` · ${h.country}`}</span>
            </li>
          ))}
        </ol>
      </section>

      {canManage && o.nextStatuses.length > 0 && (
        <div className="flex flex-wrap gap-2 border-t pt-3">
          {o.nextStatuses.map((s) => (
            <Button key={s} size="sm" variant="outline" onClick={() => void run(() => transition({ variables: { id: o.id, to: s, notifyCustomer: s === 'delivered' } }), `Pedido: ${orderLabel(s)}.`)}>
              {MOVE_LABEL[s] ?? orderLabel(s)}
            </Button>
          ))}
        </div>
      )}

      {canCancel && !showCancel && (
        <Button size="sm" variant="destructive" onClick={() => setShowCancel(true)}>
          Cancelar pedido
        </Button>
      )}
      {showCancel && (
        <form className="space-y-2 rounded-lg border p-3" onSubmit={(e) => { e.preventDefault(); void doCancel() }}>
          <h3 className="font-medium">Cancelar el pedido</h3>
          <Field id="cancel-reason" label="Motivo (lo ve el cliente)">
            <Input id="cancel-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
          </Field>
          {o.paymentStatus === 'paid' && (
            <label className="flex items-center gap-2">
              <Checkbox checked={refund} onCheckedChange={(v) => setRefund(v === true)} />
              Reembolsar el pago completo ({o.total} {o.currency})
            </label>
          )}
          <p className="text-xs text-muted-foreground">El stock vuelve al inventario con un movimiento del Kardex.</p>
          <div className="flex gap-2">
            <Button type="submit" size="sm" variant="destructive" disabled={cancelling || reason.trim() === ''}>
              Confirmar cancelación
            </Button>
            <Button type="button" size="sm" variant="outline" onClick={() => setShowCancel(false)}>
              No cancelar
            </Button>
          </div>
        </form>
      )}
    </div>
  )
}

function ShipmentForm({ order, onDone }: { order: Order; onDone: () => void }) {
  const run = useRun()
  const [create, { loading }] = useMutation(CreateShipmentDocument, { refetchQueries: [AdminOrderDocument, AdminOrdersDocument] })
  const [carrier, setCarrier] = useState('estafeta')
  const [tracking, setTracking] = useState('')
  const [url, setUrl] = useState('')
  const [service, setService] = useState('')

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const ok = await run(
      () => create({ variables: { input: { orderId: order.id, carrier, service: service.trim() || null, trackingNumber: tracking.trim() || null, trackingUrl: url.trim() || null } } }),
      'Envío creado; el cliente recibió su guía.',
    )
    if (ok) onDone()
  }

  return (
    <form className="space-y-2 rounded-lg border p-3" onSubmit={(e) => void submit(e)} aria-label="Nuevo envío">
      <p className="text-xs text-muted-foreground">Incluye todo lo que aún no se ha enviado.</p>
      <div className="grid gap-2 sm:grid-cols-2">
        <Field id="ship-carrier" label="Paquetería">
          <NativeSelect id="ship-carrier" value={carrier} onChange={(e) => setCarrier(e.target.value)}>
            {CARRIERS.map((c) => (
              <option key={c} value={c}>
                {c.toUpperCase()}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="ship-service" label="Servicio (opcional)">
          <Input id="ship-service" value={service} onChange={(e) => setService(e.target.value)} />
        </Field>
        <Field id="ship-tracking" label="Número de guía">
          <Input id="ship-tracking" value={tracking} onChange={(e) => setTracking(e.target.value)} />
        </Field>
        <Field id="ship-url" label="Enlace de rastreo (opcional)">
          <Input id="ship-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} />
        </Field>
      </div>
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={loading}>
          Crear envío
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onDone}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}

function formatAddress(a: Record<string, string | null>): string {
  return [a.recipient_name, [a.street, a.exterior_no].filter(Boolean).join(' '), a.neighborhood, `${a.city ?? ''}, ${a.state ?? ''} ${a.zip ?? ''}`, a.country_code].filter(Boolean).join(' · ')
}
