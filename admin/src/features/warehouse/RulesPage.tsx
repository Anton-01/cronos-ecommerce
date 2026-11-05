import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useRun } from '@/features/catalog/products/useRun'
import { formatQty, isValidCount } from '@/features/inventory/quantity'
import { Field } from '@/features/pricing/CurrenciesPage'
import { WhPoliciesDocument, WhPolicyHistoryDocument, WhSetThresholdDocument, WhThresholdsDocument, WhUpdatePoliciesDocument } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { ProductPicker, type PickedProduct } from './ProductPicker'
import { useWarehouse } from './useWarehouse'

const KEY_LABEL: Record<string, string> = {
  approval_units: 'Unidades que exigen aprobación',
  approval_percent: '% que exige aprobación',
  approval_amount: 'Monto que exige aprobación',
  recovery_margin_percent: 'Margen de recuperación (%)',
}

/** The rules an administrator tunes without a deployment: when a correction needs a second person, and when a product counts as low. */
export function RulesPage() {
  return (
    <Tabs defaultValue="approvals" className="max-w-3xl">
      <TabsList>
        <TabsTrigger value="approvals">Aprobaciones</TabsTrigger>
        <TabsTrigger value="thresholds">Mínimos</TabsTrigger>
      </TabsList>
      <TabsContent value="approvals">
        <Policies />
      </TabsContent>
      <TabsContent value="thresholds">
        <Thresholds />
      </TabsContent>
    </Tabs>
  )
}

function Policies() {
  const run = useRun()
  const clock = useClock()
  const { data } = useQuery(WhPoliciesDocument)
  const { data: history, refetch } = useQuery(WhPolicyHistoryDocument, { fetchPolicy: 'cache-and-network' })
  const [save, { loading }] = useMutation(WhUpdatePoliciesDocument)
  const p = data?.whPolicies
  const server = { approvalUnits: String(p?.approvalUnits ?? ''), approvalPercent: String(p?.approvalPercent ?? ''), approvalAmount: String(p?.approvalAmount ?? ''), recoveryMarginPercent: String(p?.recoveryMarginPercent ?? '') }
  const [draft, setV] = useState<typeof server | null>(null)
  const v = draft ?? server
  const ints = Object.values(v).every((x) => /^\d+$/.test(x))
  const noLimit = Number(v.approvalUnits) === 0 && Number(v.approvalPercent) === 0 && Number(v.approvalAmount) === 0

  return (
    <div className="space-y-6">
      <form
        className="space-y-4 rounded-lg border p-4"
        onSubmit={(e) => {
          e.preventDefault()
          void run(() => save({ variables: { input: { approvalUnits: Number(v.approvalUnits), approvalPercent: Number(v.approvalPercent), approvalAmount: Number(v.approvalAmount), recoveryMarginPercent: Number(v.recoveryMarginPercent) } } }).then(async () => { await refetch(); setV(null) }), 'Reglas guardadas')
        }}
      >
        <div>
          <h2 className="font-semibold">¿Cuándo una corrección necesita aprobación?</h2>
          <p className="text-sm text-muted-foreground">Una corrección pasa sola si es pequeña en todo lo que esté activo; si supera cualquier límite, espera a otra persona. Un límite en 0 no se usa.</p>
        </div>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field id="pol-u" label="Más de N unidades">
            <Input id="pol-u" inputMode="numeric" value={v.approvalUnits} onChange={(e) => setV({ ...v, approvalUnits: e.target.value })} />
          </Field>
          <Field id="pol-p" label="Más de N % de lo que había">
            <Input id="pol-p" inputMode="numeric" value={v.approvalPercent} onChange={(e) => setV({ ...v, approvalPercent: e.target.value })} />
          </Field>
          <Field id="pol-a" label="Que valga más de N (moneda principal)">
            <Input id="pol-a" inputMode="numeric" value={v.approvalAmount} onChange={(e) => setV({ ...v, approvalAmount: e.target.value })} />
          </Field>
        </div>
        <Field id="pol-m" label="Margen para dar por recuperada una existencia baja (%)">
          <Input id="pol-m" inputMode="numeric" className="max-w-32" value={v.recoveryMarginPercent} onChange={(e) => setV({ ...v, recoveryMarginPercent: e.target.value })} />
        </Field>
        {noLimit && ints && <p role="alert" className="text-sm text-destructive">Debe quedar activo al menos un límite de aprobación.</p>}
        <p className="text-xs text-muted-foreground">El monto usa el último costo de compra del producto; si no tiene costo, la corrección siempre espera aprobación.</p>
        <Button type="submit" disabled={loading || !ints || noLimit}>
          Guardar
        </Button>
      </form>

      <section aria-labelledby="pol-h" className="space-y-2">
        <h2 id="pol-h" className="font-semibold">
          Quién cambió qué
        </h2>
        <ul className="divide-y rounded-lg border text-sm">
          {(history?.whPolicyHistory ?? []).map((h, i) => (
            <li key={i} className="flex flex-wrap justify-between gap-2 px-3 py-2">
              <span>
                {KEY_LABEL[h.key] ?? h.key}: <strong>{h.value}</strong>
              </span>
              <span className="text-xs text-muted-foreground">
                {h.changedByName ?? '—'} · {clock.formatDateTime(h.changedAt)}
              </span>
            </li>
          ))}
          {(history?.whPolicyHistory ?? []).length === 0 && <li className="px-3 py-3 text-muted-foreground">Se usan los valores iniciales; aún no se ha cambiado nada.</li>}
        </ul>
      </section>
    </div>
  )
}

function Thresholds() {
  const run = useRun()
  const { current } = useWarehouse()
  const { data, refetch } = useQuery(WhThresholdsDocument, { variables: { warehouseId: current?.id }, skip: !current, fetchPolicy: 'cache-and-network' })
  const [save] = useMutation(WhSetThresholdDocument)
  const [product, setProduct] = useState<PickedProduct | null>(null)
  const [minimum, setMinimum] = useState('')
  const [critical, setCritical] = useState('')
  const valid = product !== null && isValidCount(minimum) && (critical === '' || (isValidCount(critical) && Number(critical) <= Number(minimum)))

  return (
    <div className="space-y-5">
      <p className="text-sm text-muted-foreground">Cuando las existencias de un producto bajan de su mínimo, el sistema avisa a quien esté suscrito (ver Alertas). Sin mínimo propio se usa el que tenga el producto en el catálogo.</p>
      <form
        className="space-y-3 rounded-lg border p-4"
        onSubmit={(e) => {
          e.preventDefault()
          if (!valid || !current || !product) return
          void run(() => save({ variables: { productId: product.productId, warehouseId: current.id, minimum: minimum.trim(), critical: critical.trim() || null } }).then(() => refetch()), 'Mínimo guardado').then((ok) => {
            if (ok) {
              setProduct(null)
              setMinimum('')
              setCritical('')
            }
          })
        }}
      >
        <h2 className="font-semibold">Fijar un mínimo</h2>
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
        <div className="grid gap-3 sm:grid-cols-2">
          <Field id="th-min" label="Mínimo (bajo)">
            <Input id="th-min" inputMode="decimal" value={minimum} onChange={(e) => setMinimum(e.target.value)} />
          </Field>
          <Field id="th-crit" label="Crítico (opcional, igual o menor al mínimo)">
            <Input id="th-crit" inputMode="decimal" value={critical} onChange={(e) => setCritical(e.target.value)} />
          </Field>
        </div>
        <Button type="submit" disabled={!valid}>
          Guardar mínimo
        </Button>
      </form>

      <ul className="divide-y rounded-lg border">
        {(data?.whThresholds ?? []).map((t) => (
          <li key={`${t.productId}-${t.locationId ?? 'w'}`} className="flex items-center justify-between gap-3 px-3 py-2">
            <span className="min-w-0">
              <span className="block truncate font-medium">{t.name ?? t.sku}</span>
              <span className="block text-xs text-muted-foreground">{t.locationCode ? `Anaquel ${t.locationCode}` : 'Todo el almacén'}</span>
            </span>
            <span className="text-right text-sm">
              mínimo <strong>{formatQty(t.minimum)}</strong>
              {t.critical && <> · crítico <strong>{formatQty(t.critical)}</strong></>}
            </span>
          </li>
        ))}
        {(data?.whThresholds ?? []).length === 0 && <li className="px-3 py-4 text-sm text-muted-foreground">Todavía no hay mínimos propios.</li>}
      </ul>
    </div>
  )
}
