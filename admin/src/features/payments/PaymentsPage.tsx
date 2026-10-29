import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import {
  AdminCheckoutsDocument,
  AdminPaymentDocument,
  AdminPaymentProvidersDocument,
  AdminPaymentsDocument,
  AdminWebhookEventsDocument,
  ConfigurePaymentProviderDocument,
  RefundPaymentDocument,
  ReprocessFailedWebhookEventsDocument,
  ReprocessWebhookEventDocument,
  SavePaymentCredentialsDocument,
  SwitchPaymentModeDocument,
  SyncPaymentDocument,
  TestPaymentProviderDocument,
  type AdminPaymentProvidersQuery,
  type PaymentMode,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { changedCredentials, GO_LIVE_PHRASE, matchesGoLivePhrase, needsAttention, statusLabel } from './phrase'

type Provider = AdminPaymentProvidersQuery['adminPaymentProviders'][number]

const PAGE = 25

export function PaymentsPage() {
  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Pagos" description="Stripe, Mercado Pago y PayPal. Cada proveedor trabaja en sandbox para pruebas y se pasa a producción desde aquí cuando estés listo." />
      <Tabs defaultValue="providers">
        <TabsList>
          <TabsTrigger value="providers">Proveedores</TabsTrigger>
          <TabsTrigger value="payments">Pagos</TabsTrigger>
          <TabsTrigger value="webhooks">Notificaciones</TabsTrigger>
          <TabsTrigger value="checkouts">Checkouts</TabsTrigger>
        </TabsList>
        <TabsContent value="providers">
          <ProvidersTab />
        </TabsContent>
        <TabsContent value="payments">
          <PaymentsTab />
        </TabsContent>
        <TabsContent value="webhooks">
          <WebhooksTab />
        </TabsContent>
        <TabsContent value="checkouts">
          <CheckoutsTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

export function ModeBadge({ mode }: { mode: PaymentMode }) {
  return mode === 'LIVE' ? <Badge variant="destructive">Producción</Badge> : <Badge variant="secondary">Sandbox (pruebas)</Badge>
}

function ProvidersTab() {
  const { data, loading } = useQuery(AdminPaymentProvidersDocument, { fetchPolicy: 'cache-and-network' })
  return (
    <div className="space-y-4 pt-3">
      {loading && !data && <Skeleton className="h-40 w-full" />}
      {data?.adminPaymentProviders.map((p) => (
        <ProviderCard key={p.code} provider={p} />
      ))}
    </div>
  )
}

function ProviderCard({ provider }: { provider: Provider }) {
  const { can } = useAuth()
  const run = useRun()
  const canManage = can('payments.manage')
  const canGoLive = can('payments.go-live')
  const [configure] = useMutation(ConfigurePaymentProviderDocument)
  const [test] = useMutation(TestPaymentProviderDocument)
  const [markets, setMarkets] = useState(provider.markets.join(', '))
  const [limits, setLimits] = useState({ min: provider.minAmount ?? '', max: provider.maxAmount ?? '' })
  const [goLive, setGoLive] = useState(false)

  const saveSettings = () =>
    run(
      () =>
        configure({
          variables: {
            code: provider.code,
            input: { markets: markets.split(',').map((m) => m.trim().toUpperCase()).filter(Boolean), minAmount: limits.min.trim() || null, maxAmount: limits.max.trim() || null },
          },
        }),
      'Ajustes guardados.',
    )

  const toggle = (enabled: boolean) => run(() => configure({ variables: { code: provider.code, input: { isEnabled: enabled } } }), enabled ? `${provider.name} habilitado.` : `${provider.name} deshabilitado.`)

  const check = async (mode: PaymentMode) => {
    try {
      const { data } = await test({ variables: { code: provider.code, mode } })
      toast.success(data?.testPaymentProvider ?? 'Conexión correcta.')
    } catch (e) {
      await run(() => Promise.reject(e), '')
    }
  }

  const backToSandbox = useMutation(SwitchPaymentModeDocument)[0]

  return (
    <section className="space-y-4 rounded-xl border bg-card p-4" aria-label={provider.name}>
      <header className="flex flex-wrap items-center gap-3">
        <h2 className="text-lg font-semibold">{provider.name}</h2>
        <ModeBadge mode={provider.mode} />
        {provider.mode === 'LIVE' && provider.liveSince && <LiveSince at={provider.liveSince} />}
        <span className="flex-1" />
        {canManage && (
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={provider.isEnabled} onCheckedChange={(v) => void toggle(v === true)} aria-label={`Habilitar ${provider.name}`} />
            Habilitado
          </label>
        )}
      </header>

      <p className="text-xs text-muted-foreground">
        URL de notificaciones (pégala en el panel del proveedor): <code className="select-all rounded bg-muted px-1 py-0.5">{provider.webhookUrl}</code>
      </p>

      <div className="grid gap-3 sm:grid-cols-3">
        <Field id={`${provider.code}-markets`} label="Mercados (MX, US)">
          <Input id={`${provider.code}-markets`} value={markets} disabled={!canManage} onChange={(e) => setMarkets(e.target.value)} />
        </Field>
        <Field id={`${provider.code}-min`} label="Monto mínimo">
          <Input id={`${provider.code}-min`} inputMode="decimal" value={limits.min} disabled={!canManage} onChange={(e) => setLimits({ ...limits, min: e.target.value })} />
        </Field>
        <Field id={`${provider.code}-max`} label="Monto máximo">
          <Input id={`${provider.code}-max`} inputMode="decimal" value={limits.max} disabled={!canManage} onChange={(e) => setLimits({ ...limits, max: e.target.value })} />
        </Field>
      </div>
      {canManage && (
        <Button size="sm" variant="outline" onClick={() => void saveSettings()}>
          Guardar ajustes
        </Button>
      )}

      <div className="grid gap-4 md:grid-cols-2">
        <CredentialsForm provider={provider} mode="SANDBOX" title="Credenciales de pruebas (sandbox)" canManage={canManage} onTest={check} />
        <CredentialsForm provider={provider} mode="LIVE" title="Credenciales de producción" canManage={canManage} onTest={check} />
      </div>

      {canGoLive && (
        <footer className="flex flex-wrap items-center gap-3 border-t pt-3">
          {provider.mode === 'SANDBOX' ? (
            <>
              <Button variant="destructive" disabled={!provider.liveReady} onClick={() => setGoLive(true)}>
                Pasar a producción
              </Button>
              {!provider.liveReady && <span className="text-xs text-muted-foreground">Completa las credenciales de producción para poder pasar a producción.</span>}
            </>
          ) : (
            <Button variant="outline" onClick={() => void run(() => backToSandbox({ variables: { code: provider.code, target: 'SANDBOX' } }), 'Vuelto a sandbox: los pagos nuevos serán de prueba.')}>
              Volver a sandbox
            </Button>
          )}
          <span className="text-xs text-muted-foreground">Los pagos ya creados conservan el modo con el que nacieron.</span>
        </footer>
      )}
      <GoLiveDialog open={goLive} provider={provider} onClose={() => setGoLive(false)} />
    </section>
  )
}

function LiveSince({ at }: { at: string }) {
  const clock = useClock()
  return <span className="text-xs text-muted-foreground">desde {clock.formatDateTime(at)}</span>
}

function CredentialsForm({ provider, mode, title, canManage, onTest }: { provider: Provider; mode: PaymentMode; title: string; canManage: boolean; onTest: (m: PaymentMode) => Promise<void> }) {
  const run = useRun()
  const [save, { loading }] = useMutation(SavePaymentCredentialsDocument)
  const fields = mode === 'LIVE' ? provider.live : provider.sandbox
  const [values, setValues] = useState<Record<string, string>>({})
  const typed = changedCredentials(values)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (await run(() => save({ variables: { code: provider.code, mode, values: typed } }), 'Credenciales guardadas.')) setValues({})
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-3 rounded-lg border p-3" aria-label={title}>
      <h3 className="text-sm font-medium">{title}</h3>
      {fields.map((f) => (
        <Field key={f.key} id={`${provider.code}-${mode}-${f.key}`} label={f.label}>
          <Input
            id={`${provider.code}-${mode}-${f.key}`}
            type={f.secret ? 'password' : 'text'}
            autoComplete="off"
            disabled={!canManage}
            placeholder={f.isSet ? `${f.display ?? ''} (guardada; deja vacío para conservarla)` : 'Sin configurar'}
            value={values[f.key] ?? ''}
            onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}
          />
        </Field>
      ))}
      {canManage && (
        <div className="flex gap-2">
          <Button type="submit" size="sm" disabled={loading || Object.keys(typed).length === 0}>
            Guardar
          </Button>
          <Button type="button" size="sm" variant="outline" onClick={() => void onTest(mode)}>
            Probar conexión
          </Button>
        </div>
      )}
    </form>
  )
}

function GoLiveDialog({ open, provider, onClose }: { open: boolean; provider: Provider; onClose: () => void }) {
  const run = useRun()
  const [switchMode, { loading }] = useMutation(SwitchPaymentModeDocument)
  const [phrase, setPhrase] = useState('')

  const confirm = async () => {
    if (await run(() => switchMode({ variables: { code: provider.code, target: 'LIVE', confirmation: phrase } }), `${provider.name} ya cobra dinero real.`)) {
      setPhrase('')
      onClose()
    }
  }

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Pasar {provider.name} a producción</DialogTitle>
          <DialogDescription>
            Los pagos nuevos cobrarán dinero real. Antes de cambiar probamos las credenciales de producción con el proveedor; si fallan, nada cambia.
          </DialogDescription>
        </DialogHeader>
        <Field id="go-live-phrase" label={`Escribe ${GO_LIVE_PHRASE} para confirmar`}>
          <Input id="go-live-phrase" autoComplete="off" value={phrase} onChange={(e) => setPhrase(e.target.value)} />
        </Field>
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose}>
            Cancelar
          </Button>
          <Button variant="destructive" disabled={!matchesGoLivePhrase(phrase) || loading} onClick={() => void confirm()}>
            Cobrar dinero real
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}

function Pager({ total, offset, setOffset }: { total: number; offset: number; setOffset: (n: number) => void }) {
  if (total <= PAGE) return null
  return (
    <div className="flex items-center justify-between text-sm text-muted-foreground">
      <span>
        {offset + 1}–{Math.min(offset + PAGE, total)} de {total}
      </span>
      <span className="flex gap-2">
        <Button size="sm" variant="outline" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>
          Anterior
        </Button>
        <Button size="sm" variant="outline" disabled={offset + PAGE >= total} onClick={() => setOffset(offset + PAGE)}>
          Siguiente
        </Button>
      </span>
    </div>
  )
}

function PaymentsTab() {
  const clock = useClock()
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const [status, setStatus] = useState('')
  const [mode, setMode] = useState<'' | PaymentMode>('')
  const [anomalies, setAnomalies] = useState(false)
  const [offset, setOffset] = useState(0)
  const [open, setOpen] = useState<string | null>(null)
  const { data, loading } = useQuery(AdminPaymentsDocument, {
    variables: { search: debounced || null, status: status || null, mode: mode || null, anomaliesOnly: anomalies, first: PAGE, offset },
    fetchPolicy: 'cache-and-network',
  })
  const page = data?.adminPayments

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-center gap-2">
        <Input className="w-64" type="search" aria-label="Buscar pagos" placeholder="Id, referencia, pedido o correo…" value={search} onChange={(e) => { setSearch(e.target.value); setOffset(0) }} />
        <NativeSelect aria-label="Filtrar por estado" className="w-44" value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0) }}>
          <option value="">Todos los estados</option>
          {['pending', 'requires_action', 'processing', 'captured', 'partially_refunded', 'refunded', 'failed', 'cancelled', 'disputed'].map((s) => (
            <option key={s} value={s}>
              {statusLabel(s)}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Filtrar por modo" className="w-40" value={mode} onChange={(e) => { setMode(e.target.value as '' | PaymentMode); setOffset(0) }}>
          <option value="">Sandbox y producción</option>
          <option value="SANDBOX">Solo sandbox</option>
          <option value="LIVE">Solo producción</option>
        </NativeSelect>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={anomalies} onCheckedChange={(v) => { setAnomalies(v === true); setOffset(0) }} />
          Solo con anomalías
        </label>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.rows.map((p) => (
          <li key={p.id}>
            <button type="button" className="flex w-full flex-wrap items-center gap-3 px-3 py-2.5 text-left hover:bg-muted/50" onClick={() => setOpen(p.id)}>
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                  {p.amount} {p.currency} · {p.provider}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                  {p.customerEmail ?? p.payableId} · {clock.formatDateTime(p.createdAt)}
                  {p.country && ` · ${p.country}`}
                </span>
              </span>
              <ModeBadge mode={p.mode} />
              {p.anomaly && <Badge variant="destructive">{p.anomaly}</Badge>}
              <Badge variant={needsAttention(p.status) ? 'destructive' : 'outline'}>{statusLabel(p.status)}</Badge>
            </button>
          </li>
        ))}
        {page && page.rows.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin pagos con esos filtros.</li>}
      </ul>
      {page && <Pager total={page.total} offset={offset} setOffset={setOffset} />}
      <PaymentDialog id={open} onClose={() => setOpen(null)} />
    </div>
  )
}

function PaymentDialog({ id, onClose }: { id: string | null; onClose: () => void }) {
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const { data } = useQuery(AdminPaymentDocument, { variables: { id: id ?? '' }, skip: id === null, fetchPolicy: 'cache-and-network' })
  const [sync, { loading: syncing }] = useMutation(SyncPaymentDocument, { refetchQueries: [AdminPaymentDocument, AdminPaymentsDocument] })
  const [refund, { loading: refunding }] = useMutation(RefundPaymentDocument, { refetchQueries: [AdminPaymentDocument, AdminPaymentsDocument] })
  const [amount, setAmount] = useState('')
  const [reason, setReason] = useState('')
  const [attempt, setAttempt] = useState(() => crypto.randomUUID())
  const p = data?.adminPayment
  const refundable = p ? Number(p.capturedAmount) - Number(p.refundedAmount) : 0

  const doRefund = async () => {
    if (!p) return
    // One key per attempt: if the answer is lost and the button is pressed again, the API recognises it and refunds once.
    if (await run(() => refund({ variables: { paymentId: p.id, amount: amount.trim(), reason: reason.trim() || null, idempotencyKey: attempt } }), 'Reembolso registrado.')) {
      setAmount('')
      setReason('')
      setAttempt(crypto.randomUUID())
    }
  }

  return (
    <Dialog open={id !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Pago {p ? `${p.amount} ${p.currency}` : ''}</DialogTitle>
          <DialogDescription>
            {p && `${p.provider} · ${p.providerReference ?? 'sin referencia'} · pedido ${p.payableId}`}
          </DialogDescription>
        </DialogHeader>
        {!p && <Skeleton className="h-32 w-full" />}
        {p && (
          <div className="space-y-4 text-sm">
            <div className="flex flex-wrap items-center gap-2">
              <ModeBadge mode={p.mode} />
              <Badge variant={needsAttention(p.status) ? 'destructive' : 'outline'}>{statusLabel(p.status)}</Badge>
              {p.anomaly && <Badge variant="destructive">Anomalía: {p.anomaly}</Badge>}
              <span className="text-muted-foreground">
                Cobrado {p.capturedAmount} · Reembolsado {p.refundedAmount}
              </span>
              {can('payments.manage') && (
                <Button size="sm" variant="outline" disabled={syncing} onClick={() => void run(() => sync({ variables: { id: p.id } }), 'Estado consultado al proveedor.')}>
                  Consultar al proveedor
                </Button>
              )}
            </div>
            {p.failureMessage && <p className="rounded-md bg-destructive/10 p-2 text-destructive">{p.failureCode}: {p.failureMessage}</p>}
            <p className="text-xs text-muted-foreground">
              {p.ipAddress ?? '—'} · {p.country ?? '—'} · {p.timezone ?? '—'}
            </p>

            <div>
              <h3 className="mb-1 font-medium">Bitácora (no se edita)</h3>
              <ol className="space-y-1 border-l pl-3">
                {p.transactions.map((t) => (
                  <li key={t.id} className="text-xs">
                    <span className="font-medium">{t.type}</span> → {t.status ?? '—'}
                    {t.amount && ` · ${t.amount}`}
                    {t.externalEventId && ` · ${t.externalEventId}`}
                    {t.durationMs !== null && ` · ${t.durationMs} ms`}
                    <span className="text-muted-foreground"> · {clock.formatDateTime(t.createdAt)}{t.country && ` · ${t.country}`}</span>
                    {t.error && <span className="block text-destructive">{t.error}</span>}
                  </li>
                ))}
              </ol>
            </div>

            {p.refunds.length > 0 && (
              <div>
                <h3 className="mb-1 font-medium">Reembolsos</h3>
                <ul className="space-y-1">
                  {p.refunds.map((r) => (
                    <li key={r.id} className="text-xs">
                      {r.amount} · {r.status} · {clock.formatDateTime(r.createdAt)}
                      {r.reason && ` · ${r.reason}`}
                      {r.failureMessage && <span className="text-destructive"> · {r.failureMessage}</span>}
                    </li>
                  ))}
                </ul>
              </div>
            )}

            {can('payments.refund') && refundable > 0 && (
              <form className="space-y-2 rounded-lg border p-3" onSubmit={(e) => { e.preventDefault(); void doRefund() }}>
                <h3 className="font-medium">Reembolsar (máximo {refundable.toFixed(2)})</h3>
                <div className="grid gap-2 sm:grid-cols-3">
                  <Field id="refund-amount" label="Monto">
                    <Input id="refund-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                  </Field>
                  <div className="sm:col-span-2">
                    <Field id="refund-reason" label="Motivo">
                      <Input id="refund-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
                    </Field>
                  </div>
                </div>
                <Button type="submit" size="sm" variant="destructive" disabled={refunding || !(Number(amount) > 0) || Number(amount) > refundable}>
                  Reembolsar
                </Button>
              </form>
            )}
          </div>
        )}
      </DialogContent>
    </Dialog>
  )
}

function WebhooksTab() {
  const { can } = useAuth()
  const clock = useClock()
  const run = useRun()
  const [status, setStatus] = useState('')
  const [offset, setOffset] = useState(0)
  const { data, loading } = useQuery(AdminWebhookEventsDocument, { variables: { status: status || null, first: PAGE, offset }, fetchPolicy: 'cache-and-network' })
  const [reprocess] = useMutation(ReprocessWebhookEventDocument, { refetchQueries: [AdminWebhookEventsDocument] })
  const [reprocessAll] = useMutation(ReprocessFailedWebhookEventsDocument, { refetchQueries: [AdminWebhookEventsDocument] })
  const page = data?.adminWebhookEvents
  const canManage = can('payments.manage')

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-center gap-2">
        <NativeSelect aria-label="Filtrar por estado" className="w-52" value={status} onChange={(e) => { setStatus(e.target.value); setOffset(0) }}>
          <option value="">Todas</option>
          {['received', 'processing', 'processed', 'failed', 'dead', 'ignored', 'rejected'].map((s) => (
            <option key={s} value={s}>
              {statusLabel(s)}
            </option>
          ))}
        </NativeSelect>
        <span className="flex-1" />
        {canManage && (
          <Button variant="outline" onClick={() => void run(async () => { const r = await reprocessAll({ variables: { limit: 100 } }); toast.info(`${r.data?.reprocessFailedWebhookEvents ?? 0} notificaciones en cola.`) }, 'Reprocesando fallidas…')}>
            Reprocesar fallidas
          </Button>
        )}
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.rows.map((e) => (
          <li key={e.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="min-w-0 flex-1">
              <span className="block truncate font-medium">
                {e.provider} · {e.eventType ?? 'sin tipo'}
              </span>
              <span className="block truncate text-xs text-muted-foreground">
                {e.eventId} · {clock.formatDateTime(e.receivedAt)} · intentos {e.attempts}
                {!e.signatureValid && ' · firma inválida'}
              </span>
              {e.lastError && <span className="block truncate text-xs text-destructive">{e.lastError}</span>}
            </span>
            <ModeBadge mode={e.mode} />
            <Badge variant={needsAttention(e.status) ? 'destructive' : 'outline'}>{statusLabel(e.status)}</Badge>
            {canManage && ['failed', 'dead', 'ignored', 'processed'].includes(e.status) && (
              <Button size="sm" variant="ghost" onClick={() => void run(() => reprocess({ variables: { id: e.id } }), 'Notificación en cola.')}>
                Reprocesar
              </Button>
            )}
          </li>
        ))}
        {page && page.rows.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin notificaciones.</li>}
      </ul>
      {page && <Pager total={page.total} offset={offset} setOffset={setOffset} />}
    </div>
  )
}

function CheckoutsTab() {
  const clock = useClock()
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const { data, loading } = useQuery(AdminCheckoutsDocument, { variables: { status: status || null, search: debounced || null, first: PAGE, offset: 0 }, fetchPolicy: 'cache-and-network' })

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-center gap-2">
        <Input className="w-64" type="search" aria-label="Buscar checkouts" placeholder="Id o correo…" value={search} onChange={(e) => setSearch(e.target.value)} />
        <NativeSelect aria-label="Filtrar por estado" className="w-48" value={status} onChange={(e) => setStatus(e.target.value)}>
          <option value="">Todos</option>
          {['open', 'paid', 'expired', 'cancelled', 'needs_review'].map((s) => (
            <option key={s} value={s}>
              {statusLabel(s)}
            </option>
          ))}
        </NativeSelect>
      </div>
      {loading && !data && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminCheckouts.map((c) => (
          <li key={c.id} className="space-y-1 px-3 py-2.5">
            <div className="flex flex-wrap items-center gap-3">
              <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">
                  {c.total} {c.currency} · {c.email}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                  {c.marketCode} · {clock.formatDateTime(c.createdAt)}
                  {c.couponCode && ` · cupón ${c.couponCode}`}
                  {c.fraudDecision !== 'allow' && ` · antifraude: ${c.fraudDecision}`}
                </span>
              </span>
              <Badge variant={needsAttention(c.status) ? 'destructive' : 'outline'}>{statusLabel(c.status)}</Badge>
            </div>
            {Array.isArray(c.notes) && c.notes.length > 0 && (
              <ul className="text-xs text-destructive">
                {(c.notes as { code: string; detail: string }[]).map((n, i) => (
                  <li key={i}>
                    {n.code}: {n.detail}
                  </li>
                ))}
              </ul>
            )}
          </li>
        ))}
        {data && data.adminCheckouts.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin checkouts.</li>}
      </ul>
    </div>
  )
}
