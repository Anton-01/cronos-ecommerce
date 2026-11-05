import { useQuery } from '@apollo/client/react'
import { AlertTriangle, ArrowLeftRight, ClipboardCheck, PackagePlus, Trash2, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { formatQty } from '@/features/inventory/quantity'
import { WhScanDocument } from '@/graphql/generated'

import { ActionDialog, type ActionTarget } from './ItemActions'
import { MovementList } from './MovementList'
import { MOVEMENT_LABEL, STATUS_LABEL, normalizeCode } from './lib'
import { useWarehouse } from './useWarehouse'

/** What a QR shows: everything the person at the shelf needs, whatever the product's state (draft, inactive, removed, out of stock). */
export function ItemPage() {
  const { code = '' } = useParams()
  const valid = normalizeCode(code)
  const { can } = useAuth()
  const { current } = useWarehouse()
  const { data, loading, refetch } = useQuery(WhScanDocument, { variables: { code }, skip: !valid, fetchPolicy: 'cache-and-network' })
  const [target, setTarget] = useState<ActionTarget | null>(null)

  if (!valid) return <Problem title="Código no válido" text="Revisa la etiqueta o vuelve a escanear." />
  if (loading && !data) return <Skeleton className="h-64 w-full" />
  const item = data?.whScan
  if (!item) return <Problem title="No encontramos este código" text="Puede ser de una etiqueta que no es de este sistema, o de un producto que ya no existe." />

  const onShelves = item.places.reduce((sum, p) => sum + Number(p.quantity), 0)
  const books = item.books.reduce((acc, b) => ({ onHand: acc.onHand + Number(b.onHand), reserved: acc.reserved + Number(b.reserved), available: acc.available + Number(b.available) }), { onHand: 0, reserved: 0, available: 0 })
  const pending = Number(item.pendingToPick)
  const minimum = item.thresholds[0]?.minimum
  const low = minimum !== undefined && books.available <= Number(minimum)
  const canOperate = can('warehouse.operate')
  const label = item.name ?? item.sku ?? item.stockCode
  const warehouseId = item.books[0]?.warehouseId ?? item.places[0]?.warehouseId ?? current?.id ?? ''
  const act = (kind: ActionTarget['kind'], place?: ActionTarget['place']) => setTarget({ kind, productId: item.productId, productLabel: label, warehouseId: place ? (item.places.find((p) => p.locationId === place.locationId)?.warehouseId ?? warehouseId) : warehouseId, place })

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <header className="space-y-1">
        <div className="flex flex-wrap items-center gap-2">
          <Badge variant={item.status === 'published' ? 'success' : 'secondary'}>{item.removed ? 'Eliminado' : (STATUS_LABEL[item.status] ?? item.status)}</Badge>
          {low && <Badge variant="destructive">Existencias bajas</Badge>}
        </div>
        <h2 className="text-2xl font-semibold leading-tight">{label}</h2>
        <p className="text-sm text-muted-foreground">
          SKU {item.sku ?? '—'} · Código <span className="font-mono">{item.stockCodeFormatted}</span> · {item.unit === 'meter' ? 'se vende por metro' : 'por pieza'}
        </p>
      </header>

      {(item.removed || item.status !== 'published') && (
        <p role="note" className="flex items-start gap-2 rounded-lg border border-amber-400/50 bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          {item.removed ? 'Este producto fue eliminado del catálogo; su historial se conserva.' : 'Este producto no está a la venta ahora, pero su existencia física sigue contando.'}
        </p>
      )}

      <dl className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <Stat label="En anaqueles" value={formatQty(String(onShelves))} strong />
        <Stat label="Disponible para venta" value={formatQty(String(books.available))} />
        <Stat label="Reservado" value={formatQty(String(books.reserved))} />
        <Stat label="Por surtir" value={formatQty(String(pending))} warn={pending > 0} />
      </dl>
      {minimum !== undefined && <p className="text-xs text-muted-foreground">Mínimo configurado: {formatQty(minimum)}</p>}
      {pending > 0 && (
        <p className="text-xs text-muted-foreground">
          «Por surtir» son piezas ya vendidas que todavía están en un anaquel. <Link to="/warehouse/pick" className="underline">Ir a surtir</Link>
        </p>
      )}

      {canOperate && (
        <div className="grid grid-cols-2 gap-2">
          <Button size="lg" className="h-14 text-base" onClick={() => act('receive')}>
            <PackagePlus className="size-5" /> Recibir
          </Button>
          <Button size="lg" variant="outline" className="h-14 text-base" onClick={() => act('return')}>
            <Undo2 className="size-5" /> Regresar a anaquel
          </Button>
        </div>
      )}

      <section aria-labelledby="places" className="space-y-2">
        <h3 id="places" className="text-lg font-semibold">
          Dónde está
        </h3>
        {item.places.length === 0 ? (
          <p className="rounded-lg border p-4 text-sm text-muted-foreground">Todavía no hay existencias de este producto en ningún anaquel.</p>
        ) : (
          <ul className="space-y-2">
            {item.places.map((p) => (
              <li key={p.locationId} className="rounded-lg border p-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="font-medium">
                      {p.name} <span className="font-mono text-xs text-muted-foreground">{p.code}</span>
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {p.warehouseCode}
                      {!p.isPickable && ' · no se surte de aquí'}
                      {!p.isActive && ' · ubicación inactiva'}
                    </p>
                  </div>
                  <p className="text-2xl font-semibold tabular-nums">{formatQty(p.quantity)}</p>
                </div>
                {canOperate && (
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" className="h-10" onClick={() => act('move', p)}>
                      <ArrowLeftRight className="size-4" /> Mover
                    </Button>
                    <Button size="sm" variant="outline" className="h-10" onClick={() => act('adjust', p)}>
                      <ClipboardCheck className="size-4" /> Corregir
                    </Button>
                    <Button size="sm" variant="outline" className="h-10" onClick={() => act('writeoff', p)}>
                      <Trash2 className="size-4" /> Dar de baja
                    </Button>
                  </div>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      <section aria-labelledby="recent" className="space-y-2">
        <div className="flex items-center justify-between">
          <h3 id="recent" className="text-lg font-semibold">
            Últimos movimientos
          </h3>
          {can('warehouse.audit') && (
            <Link to={`/warehouse/trail?product=${item.productId}`} className="text-sm underline">
              Ver todo
            </Link>
          )}
        </div>
        <MovementList entries={item.recent} labels={MOVEMENT_LABEL} />
      </section>

      <ActionDialog target={target} onClose={() => setTarget(null)} onDone={() => void refetch()} />
    </div>
  )
}

function Stat({ label, value, strong, warn }: { label: string; value: string; strong?: boolean; warn?: boolean }) {
  return (
    <div className={`rounded-lg border p-3 ${strong ? 'bg-primary/5' : ''}`}>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`text-2xl font-semibold tabular-nums ${warn ? 'text-amber-600' : ''}`}>{value}</dd>
    </div>
  )
}

function Problem({ title, text }: { title: string; text: string }) {
  return (
    <div className="mx-auto mt-10 max-w-md space-y-3 text-center">
      <h2 className="text-xl font-semibold">{title}</h2>
      <p className="text-sm text-muted-foreground">{text}</p>
      <Button asChild>
        <Link to="/warehouse">Volver a escanear</Link>
      </Button>
    </div>
  )
}
