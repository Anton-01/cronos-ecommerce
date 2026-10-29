import { useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { AdminAuditsDocument, type AdminAuditsQuery } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

type Entry = AdminAuditsQuery['adminAudits']['entries'][number]

const EVENT_LABEL: Record<string, string> = { created: 'Creado', updated: 'Modificado', deleted: 'Eliminado', restored: 'Restaurado' }

export function AuditPage() {
  const clock = useClock()
  const [entity, setEntity] = useState('')
  const [country, setCountry] = useState('')
  const [event, setEvent] = useState('')
  const { data, loading, fetchMore } = useQuery(AdminAuditsDocument, { variables: { entity: entity.trim() || null, country: country || null, event: event || null, before: null }, fetchPolicy: 'cache-and-network' })
  const page = data?.adminAudits

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Auditoría" description={`Registro inmutable de cambios. Cada fila guarda el país de la IP y su zona horaria; aquí se muestran en la ${clock.label} y, al pasar el cursor, en UTC.`} />
      <div className="flex flex-wrap gap-2">
        <Input className="w-56" aria-label="Filtrar por entidad" placeholder="Entidad (p. ej. CouponModel)" value={entity} onChange={(e) => setEntity(e.target.value)} />
        <NativeSelect aria-label="Filtrar por país" className="w-40" value={country} onChange={(e) => setCountry(e.target.value)}>
          <option value="">Todos los países</option>
          <option value="MX">México</option>
          <option value="US">Estados Unidos</option>
        </NativeSelect>
        <NativeSelect aria-label="Filtrar por evento" className="w-40" value={event} onChange={(e) => setEvent(e.target.value)}>
          <option value="">Todos los eventos</option>
          {Object.entries(EVENT_LABEL).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.entries.map((e) => (
          <AuditRow key={e.id} entry={e} />
        ))}
        {page && page.entries.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin registros con esos filtros.</li>}
      </ul>
      {page?.nextBefore && (
        <div className="flex justify-center">
          <Button
            variant="outline"
            onClick={() =>
              void fetchMore({
                variables: { before: page.nextBefore },
                updateQuery: (prev, { fetchMoreResult }) => ({ adminAudits: { ...fetchMoreResult.adminAudits, entries: [...prev.adminAudits.entries, ...fetchMoreResult.adminAudits.entries] } }),
              })
            }
          >
            Cargar más
          </Button>
        </div>
      )}
    </div>
  )
}

function AuditRow({ entry: e }: { entry: Entry }) {
  const clock = useClock()
  const [open, setOpen] = useState(false)
  const changed = Object.keys({ ...(e.oldValues as object | null), ...(e.newValues as object | null) })

  return (
    <li className="px-3 py-2.5 text-sm">
      <div className="flex flex-wrap items-center gap-3">
        <time dateTime={e.createdAtUtc} title={`UTC: ${e.createdAtUtc}`} className="w-40 tabular-nums">
          {clock.formatDateTime(e.createdAt)}
        </time>
        <Badge variant={e.event === 'deleted' ? 'destructive' : e.event === 'created' ? 'success' : 'secondary'}>{EVENT_LABEL[e.event] ?? e.event}</Badge>
        <span className="font-medium">{e.entity}</span>
        <span className="flex-1 truncate font-mono text-xs text-muted-foreground">{e.entityId}</span>
        <span className="text-xs text-muted-foreground">{e.userEmail ?? 'sistema'}</span>
        <span className="text-xs text-muted-foreground" title={e.timezone ?? undefined}>
          {e.country ?? '—'} · {e.ip ?? 'sin IP'}
        </span>
        {changed.length > 0 && (
          <Button size="sm" variant="ghost" aria-expanded={open} onClick={() => setOpen((o) => !o)}>
            {open ? 'Ocultar' : 'Ver cambios'}
          </Button>
        )}
      </div>
      {open && (
        <table className="mt-2 w-full text-xs">
          <tbody>
            {changed.map((key) => (
              <tr key={key} className="border-t">
                <th className="w-40 py-1 pr-2 text-left font-medium">{key}</th>
                <td className="py-1 pr-2 text-muted-foreground line-through">{show((e.oldValues as Record<string, unknown> | null)?.[key])}</td>
                <td className="py-1">{show((e.newValues as Record<string, unknown> | null)?.[key])}</td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </li>
  )
}

const show = (v: unknown) => (v === undefined || v === null ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))
