import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { useRun } from '@/features/catalog/products/useRun'
import { formatQty } from '@/features/inventory/quantity'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { WhAdjustmentRequestsDocument, WhApproveAdjustmentDocument, WhCountSessionsDocument, WhRejectAdjustmentDocument, type WhAdjustmentRequestsQuery } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { signed } from './lib'

type Req = WhAdjustmentRequestsQuery['whAdjustmentRequests'][number]

/** What waits for someone with approval rights, and what was decided (who, when, why). The limits that send a request here are set in Reglas. */
export function ApprovalsPage() {
  const { data: sessions } = useQuery(WhCountSessionsDocument, { variables: { status: 'open' }, fetchPolicy: 'cache-and-network' })
  const waiting = (sessions?.whCountSessions ?? []).filter((s) => s.inReview > 0)
  const { data: pending } = useQuery(WhAdjustmentRequestsDocument, { variables: { status: 'pending' }, fetchPolicy: 'cache-and-network' })
  const count = pending?.whAdjustmentRequests.length ?? 0

  return (
    <div className="max-w-3xl space-y-4">
      <p className="text-sm text-muted-foreground">Las correcciones grandes y los conteos que no coinciden esperan aquí. Quien las pidió no puede aprobarlas.</p>
      <Tabs defaultValue="pending">
        <TabsList>
          <TabsTrigger value="pending">Por aprobar{count > 0 ? ` (${count})` : ''}</TabsTrigger>
          <TabsTrigger value="history">Historial</TabsTrigger>
        </TabsList>
        <TabsContent value="pending" className="space-y-3">
          <RequestList status="pending" />
          {waiting.length > 0 && (
            <section className="space-y-2 pt-2">
              <h2 className="font-semibold">Conteos por revisar</h2>
              <ul className="space-y-2">
                {waiting.map((s) => (
                  <li key={s.id}>
                    <Link to={`/warehouse/count/${s.id}`} className="flex items-center justify-between rounded-lg border p-3 hover:bg-accent">
                      <span className="font-medium">{s.name}</span>
                      <Badge variant="destructive">{s.inReview} por revisar</Badge>
                    </Link>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </TabsContent>
        <TabsContent value="history">
          <RequestList status={null} />
        </TabsContent>
      </Tabs>
    </div>
  )
}

const STATUS: Record<string, { label: string; variant: 'success' | 'secondary' | 'destructive' }> = {
  pending: { label: 'Pendiente', variant: 'secondary' },
  approved: { label: 'Aprobado', variant: 'success' },
  rejected: { label: 'Rechazado', variant: 'destructive' },
}

function RequestList({ status }: { status: 'pending' | null }) {
  const clock = useClock()
  const { data, loading, refetch } = useQuery(WhAdjustmentRequestsDocument, { variables: { status }, fetchPolicy: 'cache-and-network' })
  const [deciding, setDeciding] = useState<{ req: Req; approve: boolean } | null>(null)
  const rows = (data?.whAdjustmentRequests ?? []).filter((r) => status !== null || r.status !== 'pending')

  if (!loading && rows.length === 0) return <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">{status ? 'Nada por aprobar.' : 'Todavía no hay decisiones.'}</p>
  return (
    <>
      <ul className="space-y-2">
        {rows.map((r) => {
          const st = STATUS[r.status] ?? STATUS.pending!
          return (
            <li key={r.id} className="space-y-2 rounded-lg border p-3">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="font-medium">{r.name ?? r.sku}</p>
                  <p className="text-xs text-muted-foreground">
                    {r.locationCode} · pidió {r.requestedByName ?? '—'} · {clock.formatDateTime(r.requestedAt)}
                  </p>
                </div>
                <div className="text-right">
                  <p className={`text-xl font-semibold tabular-nums ${Number(r.delta) < 0 ? 'text-destructive' : 'text-success'}`}>{signed(r.delta)}</p>
                  <Badge variant={st.variant}>{st.label}</Badge>
                </div>
              </div>
              <p className="text-sm">
                Vio <strong>{formatQty(r.seenQuantity)}</strong> y dice que hay <strong>{formatQty(r.targetQuantity)}</strong>. «{r.reason}»
              </p>
              {r.status !== 'pending' && (
                <p className="text-xs text-muted-foreground">
                  {st.label} por {r.decidedByName ?? '—'} · {clock.formatDateTime(r.decidedAt)}
                  {r.decisionNote ? ` · ${r.decisionNote}` : ''}
                </p>
              )}
              {r.status === 'pending' && (
                <div className="flex gap-2">
                  <Button className="h-10" onClick={() => setDeciding({ req: r, approve: true })}>
                    Aprobar
                  </Button>
                  <Button className="h-10" variant="outline" onClick={() => setDeciding({ req: r, approve: false })}>
                    Rechazar
                  </Button>
                </div>
              )}
            </li>
          )
        })}
      </ul>
      <Dialog open={deciding !== null} onOpenChange={(o) => !o && setDeciding(null)}>
        <DialogContent>{deciding && <DecideForm key={deciding.req.id} {...deciding} onClose={() => setDeciding(null)} onDone={() => void refetch()} />}</DialogContent>
      </Dialog>
    </>
  )
}

function DecideForm({ req, approve, onClose, onDone }: { req: Req; approve: boolean; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const [note, setNote] = useState('')
  const [ok] = useMutation(WhApproveAdjustmentDocument)
  const [no] = useMutation(WhRejectAdjustmentDocument)
  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => (approve ? ok({ variables: { id: req.id, note: note.trim() || null } }) : no({ variables: { id: req.id, note: note.trim() } })), approve ? 'Ajuste aprobado y aplicado' : 'Ajuste rechazado').then((done) => done && (onDone(), onClose()))
      }}
    >
      <DialogHeader>
        <DialogTitle>{approve ? 'Aprobar ajuste' : 'Rechazar ajuste'}</DialogTitle>
        <DialogDescription>
          {req.name ?? req.sku} en {req.locationCode}: {signed(req.delta)}. {approve ? 'Se aplicará a la cantidad que haya hoy en el anaquel y quedará registrado quién lo aprobó.' : 'No cambia nada; quedará el motivo.'}
        </DialogDescription>
      </DialogHeader>
      <Field id="dec-note" label={approve ? 'Nota (opcional)' : 'Motivo (obligatorio)'}>
        <Textarea id="dec-note" rows={2} value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <Actions onClose={onClose} disabled={!approve && note.trim() === ''} label={approve ? 'Aprobar' : 'Rechazar'} />
    </form>
  )
}
