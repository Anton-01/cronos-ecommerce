import { useQuery } from '@apollo/client/react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { AdminMissingDataDocument } from '@/graphql/generated'

import { PageHeader } from '../shared'

/** Products that still have required data pending: they cannot be published until someone fills it in or marks it as not applicable. */
export function MissingDataPage() {
  const { data, loading } = useQuery(AdminMissingDataDocument, { variables: { first: 300 }, fetchPolicy: 'cache-and-network' })
  const rows = data?.adminMissingData ?? []

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title="Datos pendientes" description="Productos a los que todavía les falta un dato obligatorio de su tipo. Ábrelos y completa la ficha, o marca el dato como «No aplica»." />
      {!loading && rows.length === 0 && <p className="rounded-lg border p-8 text-center text-sm text-muted-foreground">Nada pendiente: todos los productos con tipo tienen sus datos obligatorios.</p>}
      <ul className="divide-y rounded-lg border">
        {rows.map((r) => (
          <li key={r.productId}>
            <Link to={`/catalog/products/${r.productId}`} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 hover:bg-accent">
              <span className="min-w-0">
                <span className="block truncate font-medium">{r.name ?? r.sku}</span>
                <span className="block text-xs text-muted-foreground">
                  {r.typeName} · {r.status === 'published' ? 'publicado' : r.status === 'inactive' ? 'inactivo' : 'borrador'}
                </span>
              </span>
              <span className="flex flex-wrap gap-1">
                {r.missing.map((m) => (
                  <Badge key={m} variant="secondary">
                    {m}
                  </Badge>
                ))}
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </div>
  )
}
