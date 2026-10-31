import { useMutation, useQuery } from '@apollo/client/react'
import { Download } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import { AdminExportLinkDocument, AdminExportsDocument, AdminMarketsDocument, RequestExportDocument, type ExportType } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

export const EXPORT_TYPES: { value: ExportType; label: string }[] = [
  { value: 'ORDERS', label: 'Pedidos' },
  { value: 'ORDER_ITEMS', label: 'Partidas de pedidos' },
  { value: 'PAYMENTS', label: 'Pagos' },
  { value: 'STOCK', label: 'Existencias' },
  { value: 'CUSTOM_ORDERS', label: 'Pedidos personalizados' },
]

const STATUS: Record<string, string> = { queued: 'En cola', running: 'Generando…', ready: 'Listo', failed: 'Falló', expired: 'Vencido' }

export function ExportsPanel() {
  const clock = useClock()
  const run = useRun()
  const [type, setType] = useState<ExportType>('ORDERS')
  const [market, setMarket] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const { data: markets } = useQuery(AdminMarketsDocument)
  const { data, startPolling, stopPolling } = useQuery(AdminExportsDocument, { variables: { first: 10 }, fetchPolicy: 'cache-and-network' })
  const [request, { loading }] = useMutation(RequestExportDocument, { refetchQueries: [AdminExportsDocument] })
  const { refetch: fetchLinks } = useQuery(AdminExportLinkDocument, { skip: true })
  const exports = data?.adminExports ?? []
  const busy = exports.some((e) => e.status === 'queued' || e.status === 'running')
  // Poll only while something is being built.
  useEffect(() => {
    if (busy) startPolling(4000)
    else stopPolling()
    return () => stopPolling()
  }, [busy, startPolling, stopPolling])

  const download = async (id: string) => {
    const { data: links } = await fetchLinks({ first: 10 })
    const url = links?.adminExports.find((l) => l.id === id)?.downloadUrl
    // An attachment response downloads without leaving the page (and, unlike window.open after an await, is never blocked as a pop-up).
    if (url) window.location.assign(url)
    else toast.error('El archivo ya no está disponible. Genera la exportación de nuevo.')
  }

  return (
    <section aria-label="Exportaciones" className="space-y-3 rounded-xl border bg-card p-4">
      <div>
        <h2 className="font-medium">Exportar a Excel</h2>
        <p className="text-xs text-muted-foreground">Se genera en segundo plano y te llega un correo con el enlace. El archivo se conserva 7 días. Contiene datos de clientes: trátalo con cuidado.</p>
      </div>
      <form
        className="flex flex-wrap items-end gap-2"
        onSubmit={(e) => {
          e.preventDefault()
          void run(() => request({ variables: { type, filters: { market: market || null, from: from || null, to: to || null } } }), 'Exportación solicitada: te avisamos por correo cuando esté lista.')
        }}
      >
        <Field id="exp-type" label="Qué exportar">
          <NativeSelect id="exp-type" value={type} onChange={(e) => setType(e.target.value as ExportType)}>
            {EXPORT_TYPES.map((t) => (
              <option key={t.value} value={t.value}>
                {t.label}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="exp-market" label="Tienda">
          <NativeSelect id="exp-market" value={market} onChange={(e) => setMarket(e.target.value)}>
            <option value="">Todas</option>
            {markets?.adminMarkets.map((m) => (
              <option key={m.id} value={m.code}>
                {m.name}
              </option>
            ))}
          </NativeSelect>
        </Field>
        {type !== 'STOCK' && (
          <>
            <Field id="exp-from" label="Desde">
              <Input id="exp-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </Field>
            <Field id="exp-to" label="Hasta">
              <Input id="exp-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </Field>
          </>
        )}
        <Button type="submit" disabled={loading || (from !== '' && to !== '' && from > to)}>
          Generar archivo
        </Button>
      </form>
      <ul className="divide-y rounded-lg border text-sm">
        {exports.map((e) => (
          <li key={e.id} className="flex flex-wrap items-center gap-2 px-3 py-2">
            <span className="min-w-0 flex-1">
              <span className="font-medium">{EXPORT_TYPES.find((t) => t.value === e.type)?.label ?? e.type}</span>
              <span className="block text-xs text-muted-foreground">
                {clock.formatDateTime(e.createdAt)}
                {e.rowCount !== null && e.rowCount !== undefined && ` · ${e.rowCount} filas`}
                {e.truncated && ' · se cortó al límite de filas'}
                {e.error && ` · ${e.error}`}
              </span>
            </span>
            <Badge variant={e.status === 'failed' ? 'destructive' : e.status === 'ready' ? 'default' : 'outline'}>{STATUS[e.status] ?? e.status}</Badge>
            {e.status === 'ready' && (
              <Button size="sm" variant="outline" onClick={() => void download(e.id)}>
                <Download className="size-4" /> Descargar
              </Button>
            )}
          </li>
        ))}
        {exports.length === 0 && <li className="py-4 text-center text-xs text-muted-foreground">Aún no has generado archivos.</li>}
      </ul>
    </section>
  )
}
