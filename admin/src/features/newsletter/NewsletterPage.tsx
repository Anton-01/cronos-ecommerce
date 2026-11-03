import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { Download } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import { EraseNewsletterSubscriberDocument, NewsletterSubscribersDocument, UnsubscribeNewsletterSubscriberDocument } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { CampaignsPanel } from './CampaignsPanel'
import { toCsv } from './csv'

const FILTERS = [{ value: '', label: 'Todos' }, { value: 'confirmed', label: 'Confirmados' }, { value: 'pending', label: 'Pendientes' }, { value: 'unsubscribed', label: 'Bajas' }]
const STATUS: Record<string, string> = { confirmed: 'Confirmado', pending: 'Pendiente de confirmar', unsubscribed: 'Baja' }
const PAGE = 50

/** Newsletter list. Only CONFIRMED addresses may be written to; pending ones have not proved the address is theirs. */
export function NewsletterPage() {
  return (
    <div className="max-w-5xl space-y-4">
      <Tabs defaultValue="subscribers">
        <TabsList>
          <TabsTrigger value="subscribers">Suscriptores</TabsTrigger>
          <TabsTrigger value="campaigns">Campañas</TabsTrigger>
        </TabsList>
        <TabsContent value="subscribers"><SubscribersPanel /></TabsContent>
        <TabsContent value="campaigns"><CampaignsPanel /></TabsContent>
      </Tabs>
    </div>
  )
}

function SubscribersPanel() {
  const { can } = useAuth()
  const canManage = can('newsletter.manage')
  const clock = useClock()
  const run = useRun()
  const client = useApolloClient()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [offset, setOffset] = useState(0)
  const { data, loading, refetch } = useQuery(NewsletterSubscribersDocument, { variables: { status: status || null, search: search || null, first: PAGE, offset }, fetchPolicy: 'cache-and-network' })
  const [unsubscribe] = useMutation(UnsubscribeNewsletterSubscriberDocument)
  const [erase] = useMutation(EraseNewsletterSubscriberDocument)
  const page = data?.newsletterSubscribers
  const counts = page?.counts

  /** Downloads every confirmed address (page by page) as a CSV for the e-mail tool that sends the campaigns. */
  const exportConfirmed = () => run(async () => {
    const rows: (string | null)[][] = []
    for (let off = 0; off < 100000; off += 200) {
      const r = await client.query({ query: NewsletterSubscribersDocument, variables: { status: 'confirmed', search: null, first: 200, offset: off }, fetchPolicy: 'network-only' })
      const batch = r.data!.newsletterSubscribers.rows
      rows.push(...batch.map((s) => [s.email, s.locale, s.marketCode, s.confirmedAt, s.source]))
      if (batch.length < 200) break
    }
    const url = URL.createObjectURL(new Blob(['﻿' + toCsv(['email', 'idioma', 'tienda', 'confirmado', 'origen'], rows)], { type: 'text/csv;charset=utf-8' }))
    const a = Object.assign(document.createElement('a'), { href: url, download: `suscriptores-${new Date().toISOString().slice(0, 10)}.csv` })
    a.click()
    URL.revokeObjectURL(url)
  }, 'Exportación lista.')

  return (
    <div className="space-y-4">
      <PageHeader
        title="Boletín"
        description="Suscriptores del pie de página. Es doble confirmación: solo los confirmados aceptaron recibir correo; no se escribe a los pendientes. Las campañas se envían desde la pestaña Campañas; también puedes exportar los confirmados."
        actions={<Button size="sm" variant="outline" onClick={exportConfirmed} disabled={!counts?.confirmed}><Download className="size-4" /> Exportar confirmados (CSV)</Button>}
      />
      <div className="flex flex-wrap items-center gap-2">
        {FILTERS.map((f) => {
          const n = counts ? (f.value === '' ? counts.pending + counts.confirmed + counts.unsubscribed : counts[f.value as 'pending' | 'confirmed' | 'unsubscribed']) : null
          return <Button key={f.value} size="sm" variant={status === f.value ? 'default' : 'outline'} aria-pressed={status === f.value} onClick={() => { setStatus(f.value); setOffset(0) }}>{f.label}{n === null ? '' : ` (${n})`}</Button>
        })}
        <Input type="search" aria-label="Buscar correo" placeholder="Buscar correo…" className="ml-auto max-w-xs" value={search} onChange={(e) => { setSearch(e.target.value); setOffset(0) }} />
      </div>
      {loading && !data ? <Skeleton className="h-40 w-full" /> : !page || page.rows.length === 0 ? <p className="text-sm text-muted-foreground">No hay suscriptores en esta vista.</p> : (
        <ul className="divide-y rounded-md border">
          {page.rows.map((s) => (
            <li key={s.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
              <div>
                <p className="font-medium">{s.email}</p>
                <p className="text-xs text-muted-foreground">{s.locale} · {s.marketCode ?? '—'} · origen {s.source}{s.consentCountry ? ` · ${s.consentCountry}` : ''} · pidió {clock.formatDateTime(s.consentedAt)}{s.confirmedAt ? ` · confirmó ${clock.formatDateTime(s.confirmedAt)}` : ''}{s.unsubscribedAt ? ` · baja ${clock.formatDateTime(s.unsubscribedAt)} (${s.unsubscribedBy === 'admin' ? 'por la tienda' : 'por la persona'})` : ''}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={s.status === 'confirmed' ? 'default' : 'secondary'}>{STATUS[s.status] ?? s.status}</Badge>
                {canManage && s.status !== 'unsubscribed' && <Button size="sm" variant="outline" onClick={() => run(async () => { await unsubscribe({ variables: { id: s.id } }); await refetch() }, 'Dado de baja.')}>Dar de baja</Button>}
                {canManage && <Button size="sm" variant="ghost" onClick={() => { if (window.confirm('Esto borra el registro y la prueba de consentimiento para siempre. ¿Continuar?')) void run(async () => { await erase({ variables: { id: s.id } }); await refetch() }, 'Registro borrado.') }}>Borrar datos</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}
      {page && page.total > PAGE && (
        <div className="flex items-center justify-end gap-2 text-sm">
          <Button size="sm" variant="outline" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>Anterior</Button>
          <span>{offset + 1}–{Math.min(offset + PAGE, page.total)} de {page.total}</span>
          <Button size="sm" variant="outline" disabled={offset + PAGE >= page.total} onClick={() => setOffset(offset + PAGE)}>Siguiente</Button>
        </div>
      )}
    </div>
  )
}
