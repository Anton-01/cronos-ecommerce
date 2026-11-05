import { useMutation, useQuery } from '@apollo/client/react'
import { ArrowLeft, Plus } from 'lucide-react'
import { useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { formatQty, isValidCount } from '@/features/inventory/quantity'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { WhCloseCountDocument, WhCountEntriesDocument, WhCountSessionsDocument, WhOpenCountDocument, WhReviewCountDocument, WhSubmitCountDocument } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { LocationSelect } from './LocationSelect'
import { ProductPicker, type PickedProduct } from './ProductPicker'
import { newKey } from './lib'
import { useWarehouse } from './useWarehouse'

const STATUS: Record<string, { label: string; variant: 'success' | 'secondary' | 'destructive' }> = {
  confirmed: { label: 'Registrado', variant: 'success' },
  in_review: { label: 'En revisión', variant: 'secondary' },
  rejected: { label: 'Rechazado', variant: 'destructive' },
}

/** Count sessions: blind counts against what the system held when the session began. */
export function CountPage() {
  const { id } = useParams()
  return id ? <CountSession id={id} /> : <CountList />
}

function CountList() {
  const { current } = useWarehouse()
  const { can } = useAuth()
  const clock = useClock()
  const { data, loading, refetch } = useQuery(WhCountSessionsDocument, { variables: { warehouseId: current?.id }, skip: !current, fetchPolicy: 'cache-and-network' })
  const [creating, setCreating] = useState(false)
  const sessions = data?.whCountSessions ?? []

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm text-muted-foreground">Cuenta lo que hay en cada anaquel. No verás lo que el sistema espera: así el conteo es honesto.</p>
        {can('warehouse.approve') && current && (
          <Button onClick={() => setCreating(true)}>
            <Plus className="size-4" /> Nuevo conteo
          </Button>
        )}
      </div>
      {loading && !data && <Skeleton className="h-32 w-full" />}
      {!loading && sessions.length === 0 && <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">No hay conteos abiertos. Pide a un líder que abra uno.</p>}
      <ul className="space-y-2">
        {sessions.map((s) => (
          <li key={s.id}>
            <Link to={`/warehouse/count/${s.id}`} className="block rounded-lg border p-4 hover:bg-accent">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-medium">{s.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {s.warehouseCode}
                    {s.scopeCode ? ` · ${s.scopeCode}` : ' · todo el almacén'} · abierto {clock.formatDateTime(s.createdAt)}
                    {s.createdByName ? ` por ${s.createdByName}` : ''}
                  </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                  <Badge variant={s.status === 'open' ? 'success' : 'secondary'}>{s.status === 'open' ? 'Abierto' : 'Cerrado'}</Badge>
                  <span className="text-xs text-muted-foreground">{s.entries} conteos</span>
                  {s.inReview > 0 && <Badge variant="destructive">{s.inReview} por revisar</Badge>}
                </div>
              </div>
            </Link>
          </li>
        ))}
      </ul>
      {current && <NewCountDialog open={creating} warehouseId={current.id} onClose={() => setCreating(false)} onDone={() => void refetch()} />}
    </div>
  )
}

function NewCountDialog({ open, warehouseId, onClose, onDone }: { open: boolean; warehouseId: string; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [scope, setScope] = useState('')
  const [open_] = useMutation(WhOpenCountDocument)
  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            void run(async () => {
              const r = await open_({ variables: { warehouseId, name: name.trim(), scopeLocationId: scope || null } })
              onDone()
              onClose()
              setName('')
              navigate(`/warehouse/count/${r.data?.whOpenCount}`)
            }, 'Conteo abierto')
          }}
        >
          <DialogHeader>
            <DialogTitle>Nuevo conteo</DialogTitle>
            <DialogDescription>Al abrirlo se congela lo que el sistema tiene hoy; los movimientos de mientras se respetan.</DialogDescription>
          </DialogHeader>
          <Field id="cnt-name" label="Nombre">
            <Input id="cnt-name" value={name} onChange={(e) => setName(e.target.value)} placeholder="Conteo semanal pasillo A" maxLength={120} required />
          </Field>
          <Field id="cnt-scope" label="Qué se cuenta">
            <LocationSelect id="cnt-scope" warehouseId={warehouseId} value={scope} onChange={setScope} allowEmpty emptyLabel="Todo el almacén" />
          </Field>
          <Actions onClose={onClose} disabled={name.trim() === ''} label="Abrir conteo" />
        </form>
      </DialogContent>
    </Dialog>
  )
}

function CountSession({ id }: { id: string }) {
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const { current } = useWarehouse()
  const lead = can('warehouse.approve')
  const { data: sessions, refetch: refetchSessions } = useQuery(WhCountSessionsDocument, { variables: {}, fetchPolicy: 'cache-and-network' })
  const { data, refetch } = useQuery(WhCountEntriesDocument, { variables: { sessionId: id }, fetchPolicy: 'cache-and-network', pollInterval: 20_000 })
  const session = sessions?.whCountSessions.find((s) => s.id === id)
  const [product, setProduct] = useState<PickedProduct | null>(null)
  const [location, setLocation] = useState('')
  const [counted, setCounted] = useState('')
  const [busy, setBusy] = useState(false)
  const key = useRef(newKey())
  const [submit] = useMutation(WhSubmitCountDocument)
  const [review] = useMutation(WhReviewCountDocument)
  const [close] = useMutation(WhCloseCountDocument)
  const entries = data?.whCountEntries ?? []
  const open = session?.status === 'open'
  const valid = product !== null && location !== '' && isValidCount(counted)

  const send = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!valid || busy || !product) return
    setBusy(true)
    let status = ''
    const ok = await run(async () => {
      const r = await submit({ variables: { input: { sessionId: id, productId: product.productId, locationId: location, counted: counted.trim(), idempotencyKey: key.current } } })
      status = r.data?.whSubmitCount.status ?? ''
    }, 'Conteo registrado')
    setBusy(false)
    if (ok) {
      if (status === 'in_review') toast.info('Ese anaquel ya tenía un conteo distinto: un líder tiene que revisar cuál vale.')
      key.current = newKey()
      setProduct(null)
      setCounted('')
      void refetch()
      void refetchSessions()
    }
  }

  return (
    <div className="mx-auto max-w-2xl space-y-5">
      <Link to="/warehouse/count" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:underline">
        <ArrowLeft className="size-4" /> Todos los conteos
      </Link>
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold">{session?.name ?? 'Conteo'}</h2>
          {session && (
            <p className="text-xs text-muted-foreground">
              {session.warehouseCode}
              {session.scopeCode ? ` · ${session.scopeCode}` : ' · todo el almacén'} · {session.status === 'open' ? 'abierto' : `cerrado ${clock.formatDateTime(session.closedAt)}`}
            </p>
          )}
        </div>
        {lead && open && (
          <Button variant="outline" onClick={() => void run(() => close({ variables: { sessionId: id } }).then(() => refetchSessions()), 'Conteo cerrado')}>
            Cerrar conteo
          </Button>
        )}
      </header>

      {open && (
        <form onSubmit={(e) => void send(e)} className="space-y-4 rounded-lg border p-4" noValidate>
          <h3 className="font-semibold">Registrar un conteo</h3>
          <Field id="cnt-loc" label="1. En qué anaquel estás">
            <LocationSelect id="cnt-loc" warehouseId={session?.warehouseId ?? current?.id ?? ''} value={location} onChange={setLocation} />
          </Field>
          <div className="space-y-2">
            <p className="text-sm font-medium">2. Qué producto contaste</p>
            {product ? (
              <p className="flex items-center justify-between rounded-md bg-muted px-3 py-2 text-sm">
                <span className="font-medium">{product.label}</span>
                <button type="button" className="text-xs underline" onClick={() => setProduct(null)}>
                  Cambiar
                </button>
              </p>
            ) : (
              <ProductPicker onPick={setProduct} />
            )}
          </div>
          <Field id="cnt-qty" label="3. Cuántas hay (cuéntalas, aunque sean cero)">
            <Input id="cnt-qty" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} className="h-12 text-lg" aria-invalid={counted !== '' && !isValidCount(counted)} />
          </Field>
          <Button type="submit" size="lg" className="h-12 w-full" disabled={!valid || busy}>
            {busy ? 'Guardando…' : 'Registrar conteo'}
          </Button>
        </form>
      )}

      <section aria-labelledby="entries" className="space-y-2">
        <h3 id="entries" className="font-semibold">
          Conteos de esta sesión
        </h3>
        {entries.length === 0 && <p className="text-sm text-muted-foreground">Todavía no hay conteos.</p>}
        <ul className="space-y-2">
          {entries.map((e) => {
            const st = STATUS[e.status] ?? STATUS.confirmed!
            return (
              <li key={e.id} className="rounded-lg border p-3">
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="truncate font-medium">{e.name ?? e.sku}</p>
                    <p className="text-xs text-muted-foreground">
                      {e.locationCode} · {e.countedByName ?? '—'} · {clock.formatDateTime(e.countedAt)}
                    </p>
                  </div>
                  <div className="text-right">
                    <p className="text-xl font-semibold tabular-nums">{formatQty(e.counted)}</p>
                    <Badge variant={st.variant}>{st.label}</Badge>
                  </div>
                </div>
                {lead && <p className="mt-1 text-xs text-muted-foreground">El sistema tenía {formatQty(e.systemAtStart)} al abrir el conteo.</p>}
                {e.note && <p className="mt-1 text-sm">{e.note}</p>}
                {lead && e.status === 'in_review' && (
                  <div className="mt-2 flex gap-2">
                    <Button size="sm" className="h-10" onClick={() => void run(() => review({ variables: { entryId: e.id, approve: true } }).then(() => refetch()), 'Conteo aceptado y aplicado')}>
                      Aceptar este conteo
                    </Button>
                    <Button size="sm" variant="outline" className="h-10" onClick={() => void run(() => review({ variables: { entryId: e.id, approve: false } }).then(() => refetch()), 'Conteo rechazado')}>
                      Rechazar
                    </Button>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      </section>
    </div>
  )
}
