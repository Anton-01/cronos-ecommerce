import { useQuery } from '@apollo/client/react'
import { ImageOff, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useAuth } from '@/features/auth/AuthProvider'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { AdminProductsDocument, type ProductStatus, type ProductType } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { NativeSelect, PageHeader } from '../shared'

export const STATUS_LABEL: Record<ProductStatus, string> = { DRAFT: 'Borrador', PUBLISHED: 'Publicado', INACTIVE: 'Inactivo' }
export const TYPE_LABEL: Record<ProductType, string> = { SIMPLE: 'Simple', VARIABLE: 'Variable', GROUPED: 'Agrupado', VARIATION: 'Variación' }
const STATUS_VARIANT = { DRAFT: 'secondary', PUBLISHED: 'success', INACTIVE: 'destructive' } as const

type Cursor = { after: string | null; start: number }
const FIRST: Cursor = { after: null, start: 0 }

export function ProductsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const [status, setStatus] = useState<ProductStatus | ''>('')
  const [type, setType] = useState<ProductType | ''>('')
  const [paging, setPaging] = useState<{ key: string; cursors: Cursor[] }>({ key: '', cursors: [FIRST] })

  const resultKey = `${debounced}|${status}|${type}`
  const cursors = paging.key === resultKey ? paging.cursors : [FIRST]
  const current = cursors[cursors.length - 1] ?? FIRST

  const { data, previousData, error, loading, refetch } = useQuery(AdminProductsDocument, {
    variables: { first: 25, after: current.after, search: debounced || null, status: status || null, type: type || null },
    fetchPolicy: 'cache-and-network',
  })
  const connection = (data ?? previousData)?.adminProducts
  const rows = connection?.edges.map((e) => e.node) ?? []

  return (
    <div className="space-y-4">
      <PageHeader
        title="Productos"
        description="Listones por metro o rollo, piezas y kits."
        actions={can('catalog.manage') && <Button onClick={() => navigate('/catalog/products/new')}><Plus /> Nuevo producto</Button>}
      />
      <div className="flex flex-wrap gap-2">
        <Input type="search" aria-label="Buscar productos" placeholder="Buscar por nombre o SKU…" className="w-full sm:w-72" value={search} onChange={(e) => setSearch(e.target.value)} />
        <NativeSelect aria-label="Estado" className="w-40" value={status} onChange={(e) => setStatus(e.target.value as ProductStatus | '')}>
          <option value="">Todos los estados</option>
          {Object.entries(STATUS_LABEL).map(([v, l]) => (
            <option key={v} value={v}>
              {l}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Tipo" className="w-40" value={type} onChange={(e) => setType(e.target.value as ProductType | '')}>
          <option value="">Todos los tipos</option>
          {(['SIMPLE', 'VARIABLE', 'GROUPED'] as const).map((v) => (
            <option key={v} value={v}>
              {TYPE_LABEL[v]}
            </option>
          ))}
        </NativeSelect>
      </div>

      {error && (
        <div role="alert" className="flex items-center justify-between rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {friendlyMessage(parseApiError(error))}
          <Button size="sm" variant="outline" onClick={() => void refetch()}>
            Reintentar
          </Button>
        </div>
      )}

      <div className="rounded-xl border bg-card">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              <TableHead className="w-14" />
              <TableHead>Producto</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Estado</TableHead>
              <TableHead className="text-right">Precio</TableHead>
              <TableHead className="text-right">Stock</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading && rows.length === 0 && Array.from({ length: 6 }, (_, i) => (
              <TableRow key={i} className="hover:bg-transparent">
                {Array.from({ length: 6 }, (_, j) => (
                  <TableCell key={j}>
                    <Skeleton className="h-5 w-full max-w-32" />
                  </TableCell>
                ))}
              </TableRow>
            ))}
            {rows.map((p) => (
              <TableRow key={p.id}>
                <TableCell>
                  {p.imageUrl ? <img src={p.imageUrl} alt="" className="size-10 rounded object-cover" /> : <div className="flex size-10 items-center justify-center rounded bg-muted"><ImageOff className="size-4 text-muted-foreground" /></div>}
                </TableCell>
                <TableCell>
                  <Link to={`/catalog/products/${p.id}`} className="font-medium hover:underline">
                    {p.name ?? p.sku ?? p.id}
                  </Link>
                  <div className="text-xs text-muted-foreground">{p.sku ?? 'Sin SKU'}</div>
                </TableCell>
                <TableCell>
                  {TYPE_LABEL[p.type]}
                  {p.type === 'VARIABLE' && <span className="text-xs text-muted-foreground"> · {p.variationsCount} var.</span>}
                </TableCell>
                <TableCell>
                  <Badge variant={STATUS_VARIANT[p.status]}>{STATUS_LABEL[p.status]}</Badge>
                </TableCell>
                <TableCell className="text-right tabular-nums">{p.price ? `$${p.price}` : '—'}</TableCell>
                <TableCell className="text-right tabular-nums">{Number(p.stockQuantity)}</TableCell>
              </TableRow>
            ))}
            {!loading && rows.length === 0 && (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={6} className="py-10 text-center text-muted-foreground">
                  No hay productos con esos filtros.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
        <div className="flex items-center justify-between border-t px-3 py-2 text-sm">
          <span className="text-muted-foreground" aria-live="polite">
            {connection && connection.pageInfo.total > 0 ? `${current.start + 1}–${current.start + rows.length} de ${connection.pageInfo.total}` : 'Sin resultados'}
          </span>
          <div className="flex gap-2">
            <Button size="sm" variant="outline" disabled={cursors.length <= 1} onClick={() => setPaging({ key: resultKey, cursors: cursors.slice(0, -1) })}>
              Anterior
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={!connection?.pageInfo.hasNextPage}
              onClick={() => connection?.pageInfo.endCursor && setPaging({ key: resultKey, cursors: [...cursors, { after: connection.pageInfo.endCursor, start: current.start + rows.length }] })}
            >
              Siguiente
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}
