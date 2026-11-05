import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { Download, ShieldAlert, ShieldCheck } from 'lucide-react'
import { useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect } from '@/features/catalog/shared'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import {
  WhAlertCandidatesDocument,
  WhCompensateDocument,
  WhIntegrityDocument,
  WhTrailDocument,
  WhVerifyChainDocument,
  type WhMovementType,
  type WhTrailQuery,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { LocationSelect } from './LocationSelect'
import { MovementList } from './MovementList'
import { MOVEMENT_LABEL, download, newKey, toCsv } from './lib'
import { useWarehouse } from './useWarehouse'

type Entry = WhTrailQuery['whTrail']['entries'][number]

const PAGE = 50
const EXPORT_LIMIT = 10_000

const day = (d: string, plus = 0): string | null => {
  if (!d) return null
  const date = new Date(`${d}T00:00:00`)
  date.setDate(date.getDate() + plus)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}T00:00:00`
}

/** The whole story of the shelves: who moved what, from where, when, and what the shelf held before and after. */
export function TrailPage() {
  const { current } = useWarehouse()
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const client = useApolloClient()
  const [params, setParams] = useSearchParams()
  const [productId, setProductId] = useState(params.get('product') ?? '')
  const [locationId, setLocationId] = useState('')
  const [userId, setUserId] = useState('')
  const [type, setType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [extra, setExtra] = useState<Entry[]>([])
  const [cursor, setCursor] = useState<string | null | undefined>(undefined)
  const [fixing, setFixing] = useState<Entry | null>(null)
  const [exporting, setExporting] = useState(false)
  const [verifying, setVerifying] = useState<string | null>(null)
  const filters = { productId: productId || null, locationId: locationId || null, warehouseId: current?.id ?? null, userId: userId || null, type: (type || null) as WhMovementType | null, from: day(from), to: day(to, 1) }

  const { data, loading, refetch } = useQuery(WhTrailDocument, { variables: { ...filters, first: PAGE }, skip: !current, fetchPolicy: 'cache-and-network' })
  const { data: people } = useQuery(WhAlertCandidatesDocument, { skip: !can('warehouse.approve') })
  const { data: integrity, refetch: recheck, loading: checking } = useQuery(WhIntegrityDocument, { fetchPolicy: 'cache-and-network' })
  const [compensate] = useMutation(WhCompensateDocument)
  const entries = [...(data?.whTrail.entries ?? []), ...extra]
  const next = cursor === undefined ? data?.whTrail.nextBefore : cursor

  const reset = () => {
    setExtra([])
    setCursor(undefined)
  }
  const more = async () => {
    const last = entries.length === 0 ? null : (cursor ?? data?.whTrail.nextBefore)
    if (!last) return
    const r = await client.query({ query: WhTrailDocument, variables: { ...filters, before: last, first: PAGE }, fetchPolicy: 'network-only' })
    setExtra((e) => [...e, ...r.data!.whTrail.entries])
    setCursor(r.data!.whTrail.nextBefore)
  }

  const exportCsv = async () => {
    setExporting(true)
    try {
      const rows: Entry[] = []
      let before: string | null = null
      do {
        const r: { data?: WhTrailQuery } = await client.query({ query: WhTrailDocument, variables: { ...filters, before, first: 200 }, fetchPolicy: 'network-only' })
        rows.push(...(r.data?.whTrail.entries ?? []))
        before = r.data?.whTrail.nextBefore ?? null
      } while (before && rows.length < EXPORT_LIMIT)
      const csv = toCsv(rows, [
        { header: 'Fecha', value: (r) => clock.formatDateTime(r.createdAt) },
        { header: 'Movimiento', value: (r) => MOVEMENT_LABEL[r.type] ?? r.type },
        { header: 'SKU', value: (r) => r.sku },
        { header: 'Producto', value: (r) => r.productName },
        { header: 'Ubicación', value: (r) => r.locationCode },
        { header: 'Otra ubicación', value: (r) => r.counterpartCode },
        { header: 'Cantidad', value: (r) => r.quantity },
        { header: 'Antes', value: (r) => r.balanceBefore },
        { header: 'Después', value: (r) => r.balanceAfter },
        { header: 'Persona', value: (r) => r.userName ?? r.userEmail },
        { header: 'Correo', value: (r) => r.userEmail },
        { header: 'IP', value: (r) => r.ip },
        { header: 'País', value: (r) => r.country },
        { header: 'Nota', value: (r) => r.note },
        { header: 'Referencia', value: (r) => [r.referenceType, r.referenceId].filter(Boolean).join(':') },
        { header: 'Id del movimiento', value: (r) => r.id },
      ])
      download(`trazabilidad-${new Date().toISOString().slice(0, 10)}.csv`, csv, 'text/csv;charset=utf-8')
      toast.success(`${rows.length} movimientos exportados${rows.length >= EXPORT_LIMIT ? ` (tope de ${EXPORT_LIMIT})` : ''}.`)
    } finally {
      setExporting(false)
    }
  }

  const verify = async (m: Entry) => {
    setVerifying(m.id)
    try {
      const r = await client.query({ query: WhVerifyChainDocument, variables: { productId: m.productId, locationId: m.locationId }, fetchPolicy: 'network-only' })
      if (r.data?.whVerifyChain.ok) toast.success('La cadena de firmas de este anaquel y producto está íntegra.')
      else toast.error('La cadena de firmas NO coincide: alguien alteró el registro. Avisa a sistemas.')
    } finally {
      setVerifying(null)
    }
  }

  const health = integrity?.whIntegrity
  const broken = health && !health.ok

  return (
    <div className="space-y-4">
      <div className={`flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 ${broken ? 'border-destructive/40 bg-destructive/10' : 'bg-card'}`} role={broken ? 'alert' : 'status'}>
        <div className="flex items-start gap-2">
          {broken ? <ShieldAlert className="mt-0.5 size-5 text-destructive" aria-hidden /> : <ShieldCheck className="mt-0.5 size-5 text-success" aria-hidden />}
          <div>
            <p className="font-medium">{health ? (broken ? 'El registro tiene diferencias' : 'Registro íntegro') : 'Verificando el registro…'}</p>
            <p className="text-xs text-muted-foreground">
              {broken ? [...health.brokenChains, ...health.stockMismatches].slice(0, 2).join(' · ') : 'Cada movimiento está firmado con el anterior: si alguien lo alterara, aquí se vería.'}
            </p>
          </div>
        </div>
        <Button variant="outline" size="sm" disabled={checking} onClick={() => void recheck()}>
          Verificar ahora
        </Button>
      </div>

      <form className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2 lg:grid-cols-3" onSubmit={(e) => { e.preventDefault(); reset(); void refetch() }}>
        <Field id="tr-type" label="Tipo de movimiento">
          <NativeSelect id="tr-type" value={type} onChange={(e) => { setType(e.target.value); reset() }}>
            <option value="">Todos</option>
            {Object.entries(MOVEMENT_LABEL).map(([k, v]) => (
              <option key={k} value={k}>
                {v}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="tr-user" label="Persona">
          <NativeSelect id="tr-user" value={userId} onChange={(e) => { setUserId(e.target.value); reset() }}>
            <option value="">Todas</option>
            {(people?.whAlertCandidates ?? []).map((p) => (
              <option key={p.id} value={p.id}>
                {p.email}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="tr-loc" label="Ubicación">
          {current && <LocationSelect id="tr-loc" warehouseId={current.id} value={locationId} onChange={(v) => { setLocationId(v); reset() }} allowEmpty emptyLabel="Todas" />}
        </Field>
        <Field id="tr-from" label="Desde">
          <Input id="tr-from" type="date" value={from} onChange={(e) => { setFrom(e.target.value); reset() }} />
        </Field>
        <Field id="tr-to" label="Hasta (incluido)">
          <Input id="tr-to" type="date" value={to} onChange={(e) => { setTo(e.target.value); reset() }} />
        </Field>
        <div className="flex items-end gap-2">
          {productId && (
            <Button type="button" variant="outline" onClick={() => { setProductId(''); setParams({}); reset() }}>
              Quitar filtro de producto
            </Button>
          )}
          <Button type="button" variant="outline" disabled={exporting} onClick={() => void exportCsv()}>
            <Download className="size-4" /> {exporting ? 'Exportando…' : 'Exportar CSV'}
          </Button>
        </div>
      </form>
      <p className="text-xs text-muted-foreground">Fechas y horas en tu zona ({clock.label}).</p>

      {loading && !data ? <p className="text-sm text-muted-foreground">Cargando…</p> : <TrailList entries={entries} canFix={can('warehouse.approve')} onFix={setFixing} onVerify={(m) => void verify(m)} verifying={verifying} />}
      {next && (
        <Button variant="outline" className="w-full" onClick={() => void more()}>
          Cargar más
        </Button>
      )}

      <Dialog open={fixing !== null} onOpenChange={(o) => !o && setFixing(null)}>
        <DialogContent>{fixing && <FixForm entry={fixing} onClose={() => setFixing(null)} onDone={() => { reset(); void refetch() }} compensate={(reason, key) => compensate({ variables: { movementId: fixing.id, reason, idempotencyKey: key } })} run={run} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function TrailList({ entries, canFix, onFix, onVerify, verifying }: { entries: Entry[]; canFix: boolean; onFix: (m: Entry) => void; onVerify: (m: Entry) => void; verifying: string | null }) {
  if (entries.length === 0) return <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">No hay movimientos con esos filtros.</p>
  return (
    <ol className="space-y-2">
      {entries.map((m) => (
        <li key={m.id} className="space-y-2">
          <MovementList entries={[m]} labels={MOVEMENT_LABEL} />
          <div className="-mt-1 flex flex-wrap items-center gap-2 px-1 text-xs text-muted-foreground">
            <span className="font-medium">{m.productName ?? m.sku}</span>
            {m.ip && <span>· {m.ip}{m.country ? ` (${m.country})` : ''}</span>}
            {m.referenceType && <Badge variant="outline">{m.referenceType}</Badge>}
            <button type="button" className="underline" disabled={verifying === m.id} onClick={() => onVerify(m)}>
              Verificar firma
            </button>
            {canFix && !m.compensatedBy && m.type !== 'COMPENSATION' && (
              <button type="button" className="underline" onClick={() => onFix(m)}>
                Corregir este movimiento
              </button>
            )}
          </div>
        </li>
      ))}
    </ol>
  )
}

function FixForm({ entry, onClose, onDone, compensate, run }: { entry: Entry; onClose: () => void; onDone: () => void; compensate: (reason: string, key: string) => Promise<unknown>; run: ReturnType<typeof useRun> }) {
  const [reason, setReason] = useState('')
  const key = useRef(newKey())
  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => compensate(reason.trim(), key.current), 'Corrección registrada').then((ok) => ok && (onDone(), onClose()))
      }}
    >
      <DialogHeader>
        <DialogTitle>Corregir un movimiento</DialogTitle>
        <DialogDescription>
          Nada se borra ni se edita: se agrega un movimiento contrario que deja la cantidad como estaba y queda ligado al original. Cada movimiento se corrige una sola vez.
        </DialogDescription>
      </DialogHeader>
      <p className="rounded-md bg-muted p-3 text-sm">
        {MOVEMENT_LABEL[entry.type] ?? entry.type} de {entry.quantity} en {entry.locationCode} ({entry.productName ?? entry.sku})
      </p>
      <Field id="fix-reason" label="Por qué (obligatorio)">
        <Textarea id="fix-reason" rows={3} value={reason} maxLength={500} onChange={(e) => setReason(e.target.value)} required />
      </Field>
      <Actions onClose={onClose} disabled={reason.trim() === ''} label="Registrar corrección" />
    </form>
  )
}
