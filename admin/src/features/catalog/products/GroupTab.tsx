import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { AdminProductsDocument, SetProductGroupItemsDocument } from '@/graphql/generated'

import { type Product } from './tabs'
import { useRun } from './useRun'

export function GroupTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const run = useRun()
  const [save, { loading }] = useMutation(SetProductGroupItemsDocument)
  const [items, setItems] = useState(() => product.groupItems.map((g) => ({ productId: g.productId, name: g.name, quantity: String(Number(g.defaultQuantity)) })))
  const [search, setSearch] = useState('')
  const term = useDebouncedValue(search.trim())
  const { data } = useQuery(AdminProductsDocument, { variables: { first: 8, search: term }, skip: term.length < 2 })
  const candidates = (data?.adminProducts.edges ?? []).map((e) => e.node).filter((n) => n.id !== product.id && n.type !== 'GROUPED' && !items.some((i) => i.productId === n.id))

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Productos del kit</CardTitle>
        <CardDescription>Un producto agrupado reúne otros productos; el cliente los ve juntos con la cantidad sugerida.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {items.length === 0 && <p className="text-sm text-muted-foreground">El kit está vacío.</p>}
        {items.map((item) => (
          <div key={item.productId} className="flex items-center gap-2 rounded-lg border p-2">
            <span className="flex-1 truncate text-sm font-medium">{item.name}</span>
            <Input aria-label={`Cantidad de ${item.name}`} className="w-24" inputMode="decimal" value={item.quantity} disabled={!canEdit} onChange={(e) => setItems((l) => l.map((x) => (x.productId === item.productId ? { ...x, quantity: e.target.value } : x)))} />
            {canEdit && (
              <Button size="sm" variant="ghost" onClick={() => setItems((l) => l.filter((x) => x.productId !== item.productId))}>
                Quitar
              </Button>
            )}
          </div>
        ))}
        {canEdit && (
          <>
            <Input type="search" aria-label="Buscar producto para el kit" placeholder="Buscar producto para agregar…" value={search} onChange={(e) => setSearch(e.target.value)} />
            {candidates.length > 0 && (
              <ul className="divide-y rounded-lg border">
                {candidates.map((c) => (
                  <li key={c.id}>
                    <button type="button" className="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-accent" onClick={() => { setItems((l) => [...l, { productId: c.id, name: c.name ?? c.sku ?? c.id, quantity: '1' }]); setSearch('') }}>
                      <span>{c.name ?? c.sku}</span>
                      <span className="text-xs text-muted-foreground">{c.sku}</span>
                    </button>
                  </li>
                ))}
              </ul>
            )}
            <div className="flex justify-end">
              <Button disabled={loading} onClick={() => void run(() => save({ variables: { id: product.id, items: items.map((i) => ({ productId: i.productId, defaultQuantity: i.quantity })) } }), 'Kit guardado')}>
                Guardar kit
              </Button>
            </div>
          </>
        )}
      </CardContent>
    </Card>
  )
}
