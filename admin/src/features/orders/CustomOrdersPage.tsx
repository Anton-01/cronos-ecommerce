import { useMutation, useQuery } from '@apollo/client/react'
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import {
  AddCustomOrderStaffMessageDocument,
  AdminCustomOrderDocument,
  AdminCustomOrdersDocument,
  CreateQuoteDocument,
  SendQuoteDocument,
  SetCustomOrderStatusDocument,
  type AdminCustomOrderQuery,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { customLabel, lineAmount, validQuoteLine } from './status'

type Request = NonNullable<AdminCustomOrderQuery['adminCustomOrder']>

const PAGE = 25
const UNITS: Record<string, string> = { meter: 'Metros', piece: 'Piezas', roll: 'Rollos', service: 'Servicio' }

export function CustomOrdersPage() {
  const clock = useClock()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const [offset, setOffset] = useState(0)
  const [open, setOpen] = useState<string | null>(null)
  const { data, loading } = useQuery(AdminCustomOrdersDocument, { variables: { status: status || null, search: debounced || null, first: PAGE, offset }, fetchPolicy: 'cache-and-network' })
  const page = data?.adminCustomOrders

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Pedidos personalizados" description="Solicitud del cliente → conversación → cotización → aceptación → pedido por pagar." />
      <div className="flex flex-wrap gap-2">
        <Input className="w-64" type="search" aria-label="Buscar solicitudes" placeholder="Número, correo o título…" value={search} onChange={(e) => { setSearch(e.target.value); setOffset(0) }} />
        <NativeSelect aria-label="Estado" className="w-48" value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0) }}>
          <option value="">Todos los estados</option>
          {['submitted', 'in_review', 'quoted', 'converted', 'rejected', 'expired'].map((s) => (
            <option key={s} value={s}>
              {customLabel(s)}
            </option>
          ))}
        </NativeSelect>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.rows.map((r) => (
          <li key={r.id}>
            <button type="button" className="flex w-full flex-wrap items-center gap-3 px-3 py-2.5 text-left hover:bg-muted/50" onClick={() => setOpen(r.id)}>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                  {r.number} · {r.title}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                  {r.email} · {clock.formatDateTime(r.createdAt)}
                  {r.budget && ` · presupuesto ${r.budget}`}
                  {r.desiredDate && ` · para el ${r.desiredDate}`}
                </span>
              </span>
              <Badge variant={r.status === 'submitted' ? 'default' : 'outline'}>{customLabel(r.status)}</Badge>
            </button>
          </li>
        ))}
        {page && page.rows.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin solicitudes.</li>}
      </ul>
      {page && page.total > PAGE && (
        <div className="flex justify-between text-sm text-muted-foreground">
          <span>
            {offset + 1}–{Math.min(offset + PAGE, page.total)} de {page.total}
          </span>
          <span className="flex gap-2">
            <Button size="sm" variant="outline" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>
              Anterior
            </Button>
            <Button size="sm" variant="outline" disabled={offset + PAGE >= page.total} onClick={() => setOffset(offset + PAGE)}>
              Siguiente
            </Button>
          </span>
        </div>
      )}
      <RequestDialog id={open} onClose={() => setOpen(null)} />
    </div>
  )
}

function RequestDialog({ id, onClose }: { id: string | null; onClose: () => void }) {
  const { data } = useQuery(AdminCustomOrderDocument, { variables: { id: id ?? '' }, skip: id === null, fetchPolicy: 'cache-and-network' })
  const r = data?.adminCustomOrder
  return (
    <Dialog open={id !== null} onOpenChange={(v) => !v && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{r ? `${r.number} · ${r.title}` : 'Solicitud'}</DialogTitle>
          <DialogDescription>{r && `${r.email} · mercado ${r.marketCode}`}</DialogDescription>
        </DialogHeader>
        {!r && <Skeleton className="h-40 w-full" />}
        {r && <RequestDetail request={r} />}
      </DialogContent>
    </Dialog>
  )
}

function RequestDetail({ request: r }: { request: Request }) {
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const canManage = can('custom-orders.manage')
  const refetch = { refetchQueries: [AdminCustomOrderDocument, AdminCustomOrdersDocument] }
  const [message] = useMutation(AddCustomOrderStaffMessageDocument, refetch)
  const [setStatus] = useMutation(SetCustomOrderStatusDocument, refetch)
  const [send] = useMutation(SendQuoteDocument, refetch)
  const [body, setBody] = useState('')
  const [internal, setInternal] = useState(false)
  const [quoting, setQuoting] = useState(false)
  const [rejecting, setRejecting] = useState(false)
  const [reason, setReason] = useState('')
  const open = ['submitted', 'in_review', 'quoted'].includes(r.status)

  return (
    <div className="space-y-4 text-sm">
      <p className="whitespace-pre-wrap rounded-md bg-muted/50 p-2">{r.description}</p>
      {r.specifications != null && Object.keys(r.specifications as object).length > 0 && <pre className="overflow-x-auto rounded-md bg-muted/50 p-2 text-xs">{JSON.stringify(r.specifications, null, 2)}</pre>}
      {r.rejectionReason && <p className="rounded-md bg-destructive/10 p-2 text-destructive">Rechazada: {r.rejectionReason}</p>}

      <section aria-label="Conversación" className="space-y-2">
        <h3 className="font-medium">Conversación</h3>
        <ul className="space-y-1.5">
          {r.messages.map((m) => (
            <li key={m.id} className={`rounded-md p-2 ${m.internal ? 'border border-dashed bg-amber-50 dark:bg-amber-950/30' : m.author === 'staff' ? 'bg-primary/10' : 'bg-muted/50'}`}>
              <span className="text-xs text-muted-foreground">
                {m.author === 'staff' ? 'Equipo' : m.author === 'customer' ? 'Cliente' : 'Sistema'}
                {m.internal && ' · nota interna'} · {clock.formatDateTime(m.at)}
              </span>
              <p className="whitespace-pre-wrap">{m.body}</p>
            </li>
          ))}
        </ul>
        {canManage && open && (
          <form
            className="space-y-2"
            onSubmit={(e) => {
              e.preventDefault()
              void run(() => message({ variables: { id: r.id, body: body.trim(), internal } }), internal ? 'Nota guardada.' : 'Respuesta enviada al cliente.').then((ok) => ok && setBody(''))
            }}
          >
            <Textarea aria-label="Mensaje" value={body} onChange={(e) => setBody(e.target.value)} rows={3} />
            <div className="flex items-center gap-3">
              <label className="flex items-center gap-2">
                <Checkbox checked={internal} onCheckedChange={(v) => setInternal(v === true)} />
                Nota interna (el cliente no la ve)
              </label>
              <Button type="submit" size="sm" disabled={body.trim() === ''}>
                {internal ? 'Guardar nota' : 'Responder'}
              </Button>
            </div>
          </form>
        )}
      </section>

      <section aria-label="Cotizaciones" className="space-y-2">
        <h3 className="font-medium">Cotizaciones</h3>
        {r.quotes.length === 0 && <p className="text-xs text-muted-foreground">Aún no hay cotizaciones.</p>}
        {r.quotes.map((q) => (
          <div key={q.id} className="rounded-lg border p-2">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-medium">
                v{q.version} · {q.total} {q.currency}
              </span>
              <Badge variant={q.status === 'sent' ? 'default' : 'outline'}>{customLabel(q.status)}</Badge>
              {q.validUntil && <span className="text-xs text-muted-foreground">vigente hasta {q.validUntil}</span>}
              <span className="flex-1" />
              {canManage && q.status === 'draft' && (
                <Button size="sm" onClick={() => void run(() => send({ variables: { id: q.id } }), 'Cotización enviada al cliente.')}>
                  Enviar al cliente
                </Button>
              )}
            </div>
            <ul className="mt-1 text-xs">
              {q.lines.map((l, n) => (
                <li key={n}>
                  {l.quantity} {UNITS[l.unit] ?? l.unit} × {l.description} @ {l.unitPrice} → {l.total}
                </li>
              ))}
              <li className="text-muted-foreground">
                Envío {q.shipping} · {q.taxIncluded ? 'IVA incluido' : 'impuestos'} {q.tax}
              </li>
            </ul>
          </div>
        ))}
        {canManage && open && !quoting && (
          <Button size="sm" variant="outline" onClick={() => setQuoting(true)}>
            <Plus className="size-4" /> Nueva cotización
          </Button>
        )}
        {quoting && <QuoteForm requestId={r.id} onDone={() => setQuoting(false)} />}
      </section>

      {canManage && open && (
        <div className="flex flex-wrap items-center gap-2 border-t pt-3">
          {r.status === 'submitted' && (
            <Button size="sm" variant="outline" onClick={() => void run(() => setStatus({ variables: { id: r.id, to: 'in_review' } }), 'En revisión.')}>
              Marcar en revisión
            </Button>
          )}
          {!rejecting ? (
            <Button size="sm" variant="destructive" onClick={() => setRejecting(true)}>
              Rechazar solicitud
            </Button>
          ) : (
            <form
              className="flex flex-1 flex-wrap items-end gap-2"
              onSubmit={(e) => {
                e.preventDefault()
                void run(() => setStatus({ variables: { id: r.id, to: 'rejected', reason: reason.trim() } }), 'Solicitud rechazada; el cliente fue avisado.').then((ok) => ok && setRejecting(false))
              }}
            >
              <div className="min-w-60 flex-1">
                <Field id="reject-reason" label="Motivo (lo lee el cliente)">
                  <Input id="reject-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                </Field>
              </div>
              <Button type="submit" size="sm" variant="destructive" disabled={reason.trim() === ''}>
                Confirmar rechazo
              </Button>
            </form>
          )}
        </div>
      )}
    </div>
  )
}

interface Line {
  description: string
  unit: string
  quantity: string
  unitPrice: string
  taxRate: string
}

const EMPTY: Line = { description: '', unit: 'meter', quantity: '1', unitPrice: '', taxRate: '' }

function QuoteForm({ requestId, onDone }: { requestId: string; onDone: () => void }) {
  const run = useRun()
  const [create, { loading }] = useMutation(CreateQuoteDocument, { refetchQueries: [AdminCustomOrderDocument] })
  const [lines, setLines] = useState<Line[]>([{ ...EMPTY }])
  const [shipping, setShipping] = useState('0')
  const [validUntil, setValidUntil] = useState('')
  const [terms, setTerms] = useState('')
  const update = (i: number, patch: Partial<Line>) => setLines(lines.map((l, n) => (n === i ? { ...l, ...patch } : l)))
  const valid = lines.length > 0 && lines.every(validQuoteLine)
  const preview = lines.reduce((s, l) => s + lineAmount(l.quantity, l.unitPrice), 0)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const ok = await run(
      () =>
        create({
          variables: {
            input: {
              requestId,
              lines: lines.map((l) => ({ description: l.description.trim(), unit: l.unit, quantity: l.quantity, unitPrice: l.unitPrice, taxRate: l.taxRate.trim() || null })),
              shipping: shipping || '0',
              validUntil: validUntil || null,
              terms: terms.trim() || null,
            },
          },
        }),
      'Borrador de cotización creado. Revísalo y envíalo al cliente.',
    )
    if (ok) onDone()
  }

  return (
    <form className="space-y-3 rounded-lg border p-3" onSubmit={(e) => void submit(e)} aria-label="Nueva cotización">
      {lines.map((l, i) => (
        <div key={i} className="grid items-end gap-2 sm:grid-cols-[1fr_7rem_6rem_7rem_5rem_auto]">
          <Field id={`q-desc-${i}`} label="Descripción">
            <Input id={`q-desc-${i}`} value={l.description} onChange={(e) => update(i, { description: e.target.value })} />
          </Field>
          <Field id={`q-unit-${i}`} label="Unidad">
            <NativeSelect id={`q-unit-${i}`} value={l.unit} onChange={(e) => update(i, { unit: e.target.value })}>
              {Object.entries(UNITS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </NativeSelect>
          </Field>
          <Field id={`q-qty-${i}`} label="Cantidad">
            <Input id={`q-qty-${i}`} inputMode="decimal" value={l.quantity} onChange={(e) => update(i, { quantity: e.target.value })} />
          </Field>
          <Field id={`q-price-${i}`} label="Precio unit.">
            <Input id={`q-price-${i}`} inputMode="decimal" value={l.unitPrice} onChange={(e) => update(i, { unitPrice: e.target.value })} />
          </Field>
          <Field id={`q-tax-${i}`} label="IVA %">
            <Input id={`q-tax-${i}`} inputMode="decimal" placeholder="mercado" value={l.taxRate} onChange={(e) => update(i, { taxRate: e.target.value })} />
          </Field>
          <Button type="button" size="icon" variant="ghost" aria-label={`Quitar línea ${i + 1}`} disabled={lines.length === 1} onClick={() => setLines(lines.filter((_, n) => n !== i))}>
            <Trash2 className="size-4" />
          </Button>
        </div>
      ))}
      <Button type="button" size="sm" variant="ghost" onClick={() => setLines([...lines, { ...EMPTY }])}>
        <Plus className="size-4" /> Agregar línea
      </Button>
      <div className="grid gap-2 sm:grid-cols-3">
        <Field id="q-shipping" label="Envío">
          <Input id="q-shipping" inputMode="decimal" value={shipping} onChange={(e) => setShipping(e.target.value)} />
        </Field>
        <Field id="q-valid" label="Vigente hasta">
          <Input id="q-valid" type="date" value={validUntil} onChange={(e) => setValidUntil(e.target.value)} />
        </Field>
        <p className="self-end text-xs text-muted-foreground">Subtotal aproximado: {preview.toFixed(2)} (el sistema calcula impuestos y total)</p>
      </div>
      <Field id="q-terms" label="Condiciones (opcional)">
        <Textarea id="q-terms" rows={2} value={terms} onChange={(e) => setTerms(e.target.value)} />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" size="sm" disabled={!valid || loading}>
          Crear borrador
        </Button>
        <Button type="button" size="sm" variant="outline" onClick={onDone}>
          Cancelar
        </Button>
      </div>
    </form>
  )
}
