import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import {
  AdminMarketsDocument,
  AdminTaxClassesDocument,
  AdminTaxRatesDocument,
  CreateTaxClassDocument,
  CreateTaxRateDocument,
  DeleteTaxClassDocument,
  DeleteTaxRateDocument,
  UpdateTaxClassDocument,
  UpdateTaxRateDocument,
  type AdminTaxRatesQuery,
} from '@/graphql/generated'

import { Actions, Field } from './CurrenciesPage'

type Rate = AdminTaxRatesQuery['adminTaxRates'][number]

export function TaxesPage() {
  const { can } = useAuth()
  const canManage = can('pricing.manage')
  const run = useRun()
  const { data: classes } = useQuery(AdminTaxClassesDocument)
  const { data: markets } = useQuery(AdminMarketsDocument)
  const [marketFilter, setMarketFilter] = useState('')
  const { data: rates, loading } = useQuery(AdminTaxRatesDocument, { variables: { taxClassId: null, marketId: marketFilter || null } })
  const [createClass] = useMutation(CreateTaxClassDocument, { refetchQueries: ['AdminTaxClasses'] })
  const [updateClass] = useMutation(UpdateTaxClassDocument, { refetchQueries: ['AdminTaxClasses'] })
  const [deleteClass] = useMutation(DeleteTaxClassDocument, { refetchQueries: ['AdminTaxClasses'] })
  const [deleteRate] = useMutation(DeleteTaxRateDocument, { refetchQueries: ['AdminTaxRates'] })
  const [newClass, setNewClass] = useState('')
  const [editing, setEditing] = useState<{ rate: Rate | null } | null>(null)

  const className = (id: string) => classes?.adminTaxClasses.find((c) => c.id === id)?.name ?? ''
  const marketCode = (id: string) => markets?.adminMarkets.find((m) => m.id === id)?.code ?? ''

  return (
    <div className="max-w-4xl space-y-6">
      <PageHeader title="Impuestos" description="Clases de impuesto y las tasas que aplican en cada mercado y región." />

      <section className="space-y-2">
        <h2 className="text-sm font-semibold">Clases de impuesto</h2>
        <ul className="divide-y rounded-xl border bg-card">
          {classes?.adminTaxClasses.map((c) => (
            <li key={c.id} className="flex items-center gap-3 px-3 py-2">
              <span className="flex-1 font-medium">{c.name}</span>
              {c.isDefault ? (
                <Badge>Predeterminada</Badge>
              ) : (
                canManage && (
                  <>
                    <Button size="sm" variant="ghost" onClick={() => void run(() => updateClass({ variables: { id: c.id, isDefault: true } }), 'Clase predeterminada actualizada')}>
                      Hacer predeterminada
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => void run(() => deleteClass({ variables: { id: c.id } }), 'Clase eliminada')}>
                      Eliminar
                    </Button>
                  </>
                )
              )}
            </li>
          ))}
        </ul>
        {canManage && (
          <form
            className="flex gap-2"
            onSubmit={(e) => {
              e.preventDefault()
              void run(() => createClass({ variables: { name: newClass.trim() } }), 'Clase creada').then((ok) => ok && setNewClass(''))
            }}
          >
            <Input aria-label="Nueva clase de impuesto" placeholder="Nueva clase (p. ej. Tasa 0 %)" value={newClass} onChange={(e) => setNewClass(e.target.value)} />
            <Button type="submit" variant="outline" disabled={!newClass.trim()}>
              Agregar
            </Button>
          </form>
        )}
      </section>

      <section className="space-y-2">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold">Tasas</h2>
          <div className="flex items-center gap-2">
            <NativeSelect aria-label="Filtrar por mercado" className="w-40" value={marketFilter} onChange={(e) => setMarketFilter(e.target.value)}>
              <option value="">Todos los mercados</option>
              {markets?.adminMarkets.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.code}
                </option>
              ))}
            </NativeSelect>
            {canManage && (
              <Button onClick={() => setEditing({ rate: null })}>
                <Plus /> Nueva tasa
              </Button>
            )}
          </div>
        </div>
        {loading && !rates && <Skeleton className="h-24 w-full" />}
        <ul className="divide-y rounded-xl border bg-card">
          {rates?.adminTaxRates.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center gap-3 px-3 py-2">
              <span className="w-10 font-mono text-sm">{marketCode(r.marketId)}</span>
              <span className="w-16 text-sm text-muted-foreground">{r.region ?? 'Todo'}</span>
              <span className="flex-1 font-medium">
                {r.name} <span className="tabular-nums text-muted-foreground">{r.rate} %</span>
              </span>
              <span className="text-xs text-muted-foreground">{className(r.taxClassId)}</span>
              {r.isCompound && <Badge variant="outline">Compuesta</Badge>}
              {canManage && (
                <>
                  <Button size="sm" variant="ghost" onClick={() => setEditing({ rate: r })}>
                    Editar
                  </Button>
                  <Button size="sm" variant="ghost" onClick={() => void run(() => deleteRate({ variables: { id: r.id } }), 'Tasa eliminada')}>
                    Eliminar
                  </Button>
                </>
              )}
            </li>
          ))}
          {rates && rates.adminTaxRates.length === 0 && <li className="py-6 text-center text-sm text-muted-foreground">Sin tasas. Sin tasa, el mercado no cobra impuesto.</li>}
        </ul>
      </section>

      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent>
          {editing && (
            <RateForm
              key={editing.rate?.id ?? 'new'}
              rate={editing.rate}
              classes={classes?.adminTaxClasses ?? []}
              markets={markets?.adminMarkets ?? []}
              onClose={() => setEditing(null)}
            />
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}

function RateForm({
  rate,
  classes,
  markets,
  onClose,
}: {
  rate: Rate | null
  classes: { id: string; name: string; isDefault: boolean }[]
  markets: { id: string; code: string }[]
  onClose: () => void
}) {
  const run = useRun()
  const [create] = useMutation(CreateTaxRateDocument, { refetchQueries: ['AdminTaxRates'] })
  const [update] = useMutation(UpdateTaxRateDocument, { refetchQueries: ['AdminTaxRates'] })
  const [form, setForm] = useState({
    taxClassId: rate?.taxClassId ?? classes.find((c) => c.isDefault)?.id ?? '',
    marketId: rate?.marketId ?? markets[0]?.id ?? '',
    region: rate?.region ?? '',
    name: rate?.name ?? '',
    rate: rate?.rate ?? '',
    priority: String(rate?.priority ?? 0),
    isCompound: rate?.isCompound ?? false,
  })
  // The lists may still be loading when the form opens, so an unset choice falls back to the default class / first market.
  const taxClassId = form.taxClassId || classes.find((c) => c.isDefault)?.id || classes[0]?.id || ''
  const marketId = form.marketId || markets[0]?.id || ''
  const valid = form.name.trim() && taxClassId && marketId && form.rate !== '' && Number(form.rate) >= 0 && Number(form.rate) <= 100

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        const input = { ...form, taxClassId, marketId, name: form.name.trim(), region: form.region.trim() || null, priority: Number(form.priority) }
        void run(() => (rate ? update({ variables: { id: rate.id, input } }) : create({ variables: { input } })), 'Tasa guardada').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>{rate ? 'Editar tasa' : 'Nueva tasa'}</DialogTitle>
        <DialogDescription>Deja la región vacía para aplicar en todo el mercado, o indica el estado (p. ej. CA, TX).</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="tx-name" label="Nombre">
          <Input id="tx-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="tx-rate" label="Tasa (%)">
          <Input id="tx-rate" inputMode="decimal" value={form.rate} onChange={(e) => setForm({ ...form, rate: e.target.value })} />
        </Field>
        <Field id="tx-market" label="Mercado">
          <NativeSelect id="tx-market" value={marketId} onChange={(e) => setForm({ ...form, marketId: e.target.value })}>
            {markets.map((m) => (
              <option key={m.id} value={m.id}>
                {m.code}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="tx-region" label="Región (opcional)">
          <Input id="tx-region" maxLength={10} value={form.region} onChange={(e) => setForm({ ...form, region: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="tx-class" label="Clase de impuesto">
          <NativeSelect id="tx-class" value={taxClassId} onChange={(e) => setForm({ ...form, taxClassId: e.target.value })}>
            {classes.map((c) => (
              <option key={c.id} value={c.id}>
                {c.name}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="tx-priority" label="Prioridad">
          <Input id="tx-priority" inputMode="numeric" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} />
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isCompound} onCheckedChange={(v) => setForm({ ...form, isCompound: v === true })} />
        Compuesta (se calcula sobre el precio más los impuestos anteriores)
      </label>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}
