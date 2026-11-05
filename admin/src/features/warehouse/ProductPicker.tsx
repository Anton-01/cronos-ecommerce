import { useApolloClient, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { WhScanDocument, WhSearchProductsDocument } from '@/graphql/generated'

import { Scanner } from './Scanner'
import { formatCode, parseScanned } from './lib'

export interface PickedProduct {
  productId: string
  label: string
  stockCode: string
}

/** Choose a product by camera, by typing its code, or by name/SKU. */
export function ProductPicker({ onPick }: { onPick: (p: PickedProduct) => void }) {
  const client = useApolloClient()
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const { data } = useQuery(WhSearchProductsDocument, { variables: { search: debounced, first: 8 }, skip: debounced.length < 2 })

  const fromScan = async (text: string) => {
    const parsed = parseScanned(text)
    if (!parsed || parsed.kind !== 'item') return void toast.error('Ese código no es de un producto.')
    const { data: d } = await client.query({ query: WhScanDocument, variables: { code: parsed.code }, fetchPolicy: 'network-only' })
    const s = d?.whScan
    if (!s) return void toast.error('No encontramos ese código.')
    onPick({ productId: s.productId, label: s.name ?? s.sku ?? s.stockCode, stockCode: s.stockCode })
  }

  return (
    <div className="space-y-3">
      <Scanner onCode={(t) => void fromScan(t)} />
      <div>
        <label htmlFor="pp-search" className="sr-only">
          Buscar producto por código, nombre o SKU
        </label>
        <Input id="pp-search" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Código, nombre o SKU" className="h-12" autoComplete="off" />
      </div>
      {debounced.length >= 2 && (
        <ul className="divide-y rounded-lg border">
          {(data?.whSearchProducts ?? []).map((p) => (
            <li key={p.productId}>
              <button type="button" className="flex min-h-14 w-full items-center justify-between gap-3 px-4 py-2 text-left hover:bg-accent" onClick={() => p.stockCode && onPick({ productId: p.productId, label: p.name ?? p.sku ?? p.stockCode, stockCode: p.stockCode })}>
                <span className="min-w-0 truncate font-medium">{p.name ?? p.sku}</span>
                <span className="shrink-0 text-xs text-muted-foreground">{p.sku ?? (p.stockCode ? formatCode(p.stockCode) : '')}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
