import { useQuery } from '@apollo/client/react'
import { X } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { AdminProductsDocument } from '@/graphql/generated'

/** Search-and-pick of specific products (used to restrict a coupon or promotion). Keeps the names of what was picked. */
export function ProductPicker({ value, names, onChange }: { value: string[]; names: Record<string, string>; onChange: (ids: string[], names: Record<string, string>) => void }) {
  const [term, setTerm] = useState('')
  const debounced = useDebouncedValue(term.trim())
  const { data } = useQuery(AdminProductsDocument, { variables: { first: 8, search: debounced }, skip: debounced.length < 2 })
  const results = (data?.adminProducts.edges ?? []).map((e) => e.node).filter((p) => !value.includes(p.id))

  return (
    <div className="space-y-2">
      <label className="text-sm text-muted-foreground" htmlFor="product-picker">
        Solo estos productos:
      </label>
      <div className="flex flex-wrap gap-1.5">
        {value.map((id) => (
          <Badge key={id} variant="secondary" className="gap-1">
            {names[id] ?? id}
            <button type="button" aria-label={`Quitar ${names[id] ?? id}`} onClick={() => onChange(value.filter((v) => v !== id), names)}>
              <X className="size-3" />
            </button>
          </Badge>
        ))}
      </div>
      <Input id="product-picker" type="search" placeholder="Buscar producto por nombre o SKU…" value={term} onChange={(e) => setTerm(e.target.value)} />
      {results.length > 0 && (
        <ul className="divide-y rounded-md border text-sm">
          {results.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                className="flex w-full items-center justify-between px-2 py-1.5 text-left hover:bg-accent"
                onClick={() => {
                  onChange([...value, p.id], { ...names, [p.id]: p.name ?? p.sku ?? p.id })
                  setTerm('')
                }}
              >
                <span>{p.name ?? p.sku}</span>
                <span className="text-xs text-muted-foreground">{p.sku}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
