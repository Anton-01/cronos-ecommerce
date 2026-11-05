import { useQuery } from '@apollo/client/react'
import { Link, useParams } from 'react-router-dom'

import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { formatQty } from '@/features/inventory/quantity'
import { WhLocationByCodeDocument } from '@/graphql/generated'

import { useWarehouse } from './useWarehouse'

/** Scanning a shelf's own QR: what is on it. */
export function ShelfPage() {
  const { code = '' } = useParams()
  const { current } = useWarehouse()
  const { data, loading } = useQuery(WhLocationByCodeDocument, { variables: { code, warehouseId: current?.id }, skip: !current, fetchPolicy: 'cache-and-network' })
  if (loading && !data) return <Skeleton className="h-40 w-full" />
  const shelf = data?.whLocationByCode
  if (!shelf) {
    return (
      <div className="mx-auto mt-10 max-w-md space-y-3 text-center">
        <h2 className="text-xl font-semibold">No encontramos la ubicación «{code}»</h2>
        <Button asChild>
          <Link to="/warehouse">Volver a escanear</Link>
        </Button>
      </div>
    )
  }
  const l = shelf.location
  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <header>
        <p className="font-mono text-sm text-muted-foreground">{l.code}</p>
        <h2 className="text-2xl font-semibold">{l.name}</h2>
        <p className="text-sm text-muted-foreground">
          {formatQty(l.units)} unidades de {l.products} producto(s) aquí{!l.isActive && ' · ubicación inactiva'}
        </p>
      </header>
      <ul className="divide-y rounded-lg border">
        {shelf.contents.length === 0 && <li className="p-4 text-sm text-muted-foreground">Vacía.</li>}
        {shelf.contents.map((c) => (
          <li key={c.productId}>
            <Link to={`/warehouse/item/${c.stockCode}`} className="flex min-h-14 items-center justify-between gap-3 px-4 py-2 hover:bg-accent">
              <span className="min-w-0">
                <span className="block truncate font-medium">{c.name ?? c.sku}</span>
                <span className="block text-xs text-muted-foreground">{c.sku}</span>
              </span>
              <span className="text-xl font-semibold tabular-nums">{formatQty(c.quantity)}</span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
