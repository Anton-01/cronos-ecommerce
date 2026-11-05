import { useMutation, useQuery } from '@apollo/client/react'
import { PackageCheck } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { formatQty, isValidQty } from '@/features/inventory/quantity'
import { Field } from '@/features/pricing/CurrenciesPage'
import { WhPickDocument, WhPickLinesDocument, type WhPickLinesQuery } from '@/graphql/generated'

import { newKey } from './lib'
import { useWarehouse } from './useWarehouse'

type Line = WhPickLinesQuery['whPickLines'][number]
type Shelf = Line['shelves'][number]

/** Paid orders waiting to leave a shelf: the person takes the piece and confirms from which shelf, so the shelf and the order agree. */
export function PickPage() {
  const { current } = useWarehouse()
  const { can } = useAuth()
  const { data, loading, refetch } = useQuery(WhPickLinesDocument, { variables: { warehouseId: current?.id }, skip: !current, fetchPolicy: 'cache-and-network', pollInterval: 30_000 })
  const [chosen, setChosen] = useState<{ line: Line; shelf: Shelf } | null>(null)

  const lines = data?.whPickLines ?? []
  const byOrder = new Map<string, Line[]>()
  for (const l of lines) byOrder.set(l.orderId, [...(byOrder.get(l.orderId) ?? []), l])

  if (loading && !data) return <Skeleton className="h-48 w-full" />

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <p className="text-sm text-muted-foreground">Pedidos pagados que todavía tienen piezas en un anaquel. Toma la pieza y confirma de dónde la sacaste.</p>
      {byOrder.size === 0 && (
        <div className="rounded-lg border p-8 text-center">
          <PackageCheck className="mx-auto mb-2 size-8 text-success" aria-hidden />
          <p className="font-medium">Todo surtido</p>
          <p className="text-sm text-muted-foreground">No hay pedidos esperando.</p>
        </div>
      )}
      {[...byOrder.entries()].map(([orderId, items]) => (
        <section key={orderId} aria-label={`Pedido ${items[0]!.orderNumber ?? orderId}`} className="space-y-2 rounded-lg border p-3">
          <h2 className="font-semibold">Pedido {items[0]!.orderNumber ?? orderId.slice(-8).toUpperCase()}</h2>
          <ul className="space-y-3">
            {items.map((l) => (
              <li key={l.productId} className="space-y-2">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <Link to={`/warehouse/item/${l.stockCode}`} className="block truncate font-medium underline-offset-2 hover:underline">
                      {l.name ?? l.sku}
                    </Link>
                    <p className="text-xs text-muted-foreground">{l.sku}</p>
                  </div>
                  <Badge variant="secondary" className="text-base">
                    faltan {formatQty(l.pending)}
                  </Badge>
                </div>
                {l.shelves.length === 0 ? (
                  <p className="text-sm text-destructive">No hay existencias registradas en ningún anaquel. Revisa Recepción o cuenta el producto.</p>
                ) : (
                  <div className="flex flex-wrap gap-2" role="group" aria-label="Surtir desde">
                    {l.shelves.map((s) => (
                      <Button key={s.locationId} variant={s.isPickable ? 'outline' : 'ghost'} disabled={!s.isPickable || !can('warehouse.operate')} className="h-12" onClick={() => setChosen({ line: l, shelf: s })}>
                        {s.code} <span className="ml-1 text-muted-foreground">({formatQty(s.quantity)})</span>
                      </Button>
                    ))}
                  </div>
                )}
              </li>
            ))}
          </ul>
        </section>
      ))}
      <Dialog open={chosen !== null} onOpenChange={(o) => !o && setChosen(null)}>
        <DialogContent>{chosen && <PickForm key={`${chosen.line.orderId}-${chosen.line.productId}-${chosen.shelf.locationId}`} line={chosen.line} shelf={chosen.shelf} onClose={() => setChosen(null)} onDone={() => void refetch()} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function PickForm({ line, shelf, onClose, onDone }: { line: Line; shelf: Shelf; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const key = useRef(newKey())
  const max = Math.min(Number(line.pending), Number(shelf.quantity))
  const [qty, setQty] = useState(String(max))
  const [busy, setBusy] = useState(false)
  const [pick] = useMutation(WhPickDocument)
  const valid = isValidQty(qty) && Number(qty) <= max

  return (
    <form
      noValidate
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        if (!valid || busy) return
        setBusy(true)
        void run(() => pick({ variables: { input: { orderId: line.orderId, productId: line.productId, locationId: shelf.locationId, quantity: qty.trim(), idempotencyKey: key.current } } }), 'Surtido confirmado').then((ok) => {
          setBusy(false)
          if (ok) {
            onDone()
            onClose()
          }
        })
      }}
    >
      <DialogHeader>
        <DialogTitle>Confirmar surtido</DialogTitle>
        <DialogDescription>
          {line.name ?? line.sku} · desde {shelf.code} (hay {formatQty(shelf.quantity)}) · faltan {formatQty(line.pending)}
        </DialogDescription>
      </DialogHeader>
      <Field id="pick-qty" label="Cuántas sacaste">
        <Input id="pick-qty" inputMode="decimal" autoFocus value={qty} onChange={(e) => setQty(e.target.value)} className="h-12 text-lg" aria-invalid={!valid} />
      </Field>
      {!valid && qty !== '' && <p className="text-sm text-destructive">Puede ser hasta {formatQty(String(max))}.</p>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" size="lg" disabled={!valid || busy}>
          {busy ? 'Guardando…' : 'Confirmar'}
        </Button>
      </div>
    </form>
  )
}
