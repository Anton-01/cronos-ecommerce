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
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import {
  AdminCategoriesDocument,
  AdminCurrenciesDocument,
  AdminMarketsDocument,
  AdminPromotionsDocument,
  CreatePromotionDocument,
  DeletePromotionDocument,
  type PromotionActionType,
  UpdatePromotionDocument,
  type AdminPromotionsQuery,
} from '@/graphql/generated'

import { fromApiDate, toApiDate } from './dates'

type Promotion = AdminPromotionsQuery['adminPromotions'][number]

const ACTION_LABEL: Record<string, string> = {
  PERCENTAGE_OFF: 'Porcentaje de descuento',
  FIXED_OFF: 'Monto de descuento',
  FREE_SHIPPING: 'Envío gratis',
  BUY_X_GET_Y: 'Lleva X, recibe Y',
}

export function describeAction(a: Promotion['action']): string {
  switch (a.type) {
    case 'PERCENTAGE_OFF':
      return `${Number(a.value)} % de descuento`
    case 'FIXED_OFF':
      return `${Number(a.value)} ${a.currency ?? ''} de descuento`
    case 'FREE_SHIPPING':
      return 'Envío gratis'
    default:
      return `Compra ${a.buy}, lleva ${a.get} con ${Number(a.percent)} % de descuento`
  }
}

export function PromotionsPage() {
  const { can } = useAuth()
  const canManage = can('promotions.manage')
  const run = useRun()
  const { data, loading } = useQuery(AdminPromotionsDocument)
  const [remove] = useMutation(DeletePromotionDocument, { refetchQueries: ['AdminPromotions'] })
  const [editing, setEditing] = useState<{ promotion: Promotion | null } | null>(null)

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader
        title="Promociones"
        description="Descuentos automáticos, sin código. Se aplican por prioridad (el número menor primero); una no acumulable se aplica sola."
        actions={canManage && <Button onClick={() => setEditing({ promotion: null })}><Plus /> Nueva promoción</Button>}
      />
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminPromotions.map((p) => (
          <li key={p.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-8 text-center text-sm tabular-nums text-muted-foreground" title="Prioridad">
              {p.priority}
            </span>
            <span className="flex-1">
              <span className="font-medium">{p.name}</span> <span className="text-muted-foreground">· {describeAction(p.action)}</span>
            </span>
            {!p.isStackable && <Badge variant="secondary">Exclusiva</Badge>}
            {!p.isActive && <Badge variant="outline">Inactiva</Badge>}
            {canManage && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ promotion: p })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: p.id } }), 'Promoción eliminada')}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
        {data && data.adminPromotions.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin promociones.</li>}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">{editing && <PromotionForm key={editing.promotion?.id ?? 'new'} promotion={editing.promotion} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function PromotionForm({ promotion, onClose }: { promotion: Promotion | null; onClose: () => void }) {
  const run = useRun()
  const { data: currencies } = useQuery(AdminCurrenciesDocument)
  const { data: markets } = useQuery(AdminMarketsDocument)
  const { data: categories } = useQuery(AdminCategoriesDocument)
  const [create] = useMutation(CreatePromotionDocument, { refetchQueries: ['AdminPromotions'] })
  const [update] = useMutation(UpdatePromotionDocument, { refetchQueries: ['AdminPromotions'] })
  const a = promotion?.action
  const [form, setForm] = useState({
    name: promotion?.name ?? '',
    description: promotion?.description ?? '',
    priority: String(promotion?.priority ?? 0),
    isStackable: promotion?.isStackable ?? true,
    isActive: promotion?.isActive ?? true,
    startsAt: fromApiDate(promotion?.startsAt),
    endsAt: fromApiDate(promotion?.endsAt),
    type: (a?.type ?? 'PERCENTAGE_OFF') as PromotionActionType,
    value: a?.value ? String(Number(a.value)) : '',
    currency: a?.currency ?? 'MXN',
    buy: String(a?.buy ?? 1),
    get: String(a?.get ?? 1),
    percent: a?.percent ? String(Number(a.percent)) : '100',
    minSubtotal: promotion?.conditions.minSubtotal ?? '',
    marketCodes: promotion?.conditions.marketCodes ?? [],
    categoryIds: promotion?.conditions.categoryIds ?? [],
    excludeSaleItems: promotion?.conditions.excludeSaleItems ?? false,
    firstOrderOnly: promotion?.conditions.firstOrderOnly ?? false,
  })
  const toggle = (key: 'marketCodes' | 'categoryIds', value: string) => setForm((f) => ({ ...f, [key]: f[key].includes(value) ? f[key].filter((v) => v !== value) : [...f[key], value] }))

  const valid =
    form.name.trim() &&
    (form.type === 'FREE_SHIPPING' ||
      (form.type === 'BUY_X_GET_Y' ? Number(form.buy) >= 1 && Number(form.get) >= 1 && Number(form.percent) > 0 && Number(form.percent) <= 100 : Number(form.value) > 0 && (form.type !== 'PERCENTAGE_OFF' || Number(form.value) <= 100)))

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const action =
      form.type === 'PERCENTAGE_OFF'
        ? { type: form.type, value: form.value }
        : form.type === 'FIXED_OFF'
          ? { type: form.type, value: form.value, currency: form.currency }
          : form.type === 'BUY_X_GET_Y'
            ? { type: form.type, buy: Number(form.buy), get: Number(form.get), percent: form.percent }
            : { type: form.type }
    const input = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      priority: Number(form.priority) || 0,
      isStackable: form.isStackable,
      isActive: form.isActive,
      startsAt: toApiDate(form.startsAt),
      endsAt: toApiDate(form.endsAt),
      conditions: { minSubtotal: form.minSubtotal.trim() || null, marketCodes: form.marketCodes, categoryIds: form.categoryIds, excludeSaleItems: form.excludeSaleItems, firstOrderOnly: form.firstOrderOnly },
      action,
    }
    void run(() => (promotion ? update({ variables: { id: promotion.id, input } }) : create({ variables: { input } })), 'Promoción guardada').then((ok) => ok && onClose())
  }

  return (
    <form className="space-y-4" onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>{promotion ? 'Editar promoción' : 'Nueva promoción'}</DialogTitle>
        <DialogDescription>Las fechas están en hora UTC.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="pr-name" label="Nombre">
          <Input id="pr-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="pr-priority" label="Prioridad">
          <Input id="pr-priority" inputMode="numeric" value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })} />
        </Field>
        <Field id="pr-type" label="Qué hace">
          <NativeSelect id="pr-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as PromotionActionType })}>
            {Object.entries(ACTION_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </NativeSelect>
        </Field>
        {(form.type === 'PERCENTAGE_OFF' || form.type === 'FIXED_OFF') && (
          <Field id="pr-value" label={form.type === 'PERCENTAGE_OFF' ? 'Descuento (%)' : 'Monto'}>
            <Input id="pr-value" inputMode="decimal" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
          </Field>
        )}
        {form.type === 'FIXED_OFF' && (
          <Field id="pr-currency" label="Moneda">
            <NativeSelect id="pr-currency" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
              {currencies?.adminCurrencies.map((c) => (
                <option key={c.id}>{c.code}</option>
              ))}
            </NativeSelect>
          </Field>
        )}
        {form.type === 'BUY_X_GET_Y' && (
          <>
            <Field id="pr-buy" label="Compra (unidades)">
              <Input id="pr-buy" inputMode="numeric" value={form.buy} onChange={(e) => setForm({ ...form, buy: e.target.value })} />
            </Field>
            <Field id="pr-get" label="Lleva (unidades)">
              <Input id="pr-get" inputMode="numeric" value={form.get} onChange={(e) => setForm({ ...form, get: e.target.value })} />
            </Field>
            <Field id="pr-percent" label="Descuento en las que lleva (%)">
              <Input id="pr-percent" inputMode="decimal" value={form.percent} onChange={(e) => setForm({ ...form, percent: e.target.value })} />
            </Field>
          </>
        )}
        <Field id="pr-min" label="Compra mínima (opcional)">
          <Input id="pr-min" inputMode="decimal" value={form.minSubtotal} onChange={(e) => setForm({ ...form, minSubtotal: e.target.value })} />
        </Field>
        <Field id="pr-starts" label="Desde">
          <Input id="pr-starts" type="datetime-local" value={form.startsAt} onChange={(e) => setForm({ ...form, startsAt: e.target.value })} />
        </Field>
        <Field id="pr-ends" label="Hasta">
          <Input id="pr-ends" type="datetime-local" value={form.endsAt} onChange={(e) => setForm({ ...form, endsAt: e.target.value })} />
        </Field>
      </div>
      <fieldset className="space-y-2 rounded-md border p-3">
        <legend className="px-1 text-xs font-medium text-muted-foreground">Condiciones</legend>
        <div className="flex flex-wrap gap-3 text-sm">
          <span className="text-muted-foreground">Mercados:</span>
          {markets?.adminMarkets.map((m) => (
            <label key={m.id} className="flex items-center gap-1.5">
              <Checkbox checked={form.marketCodes.includes(m.code)} onCheckedChange={() => toggle('marketCodes', m.code)} />
              {m.code}
            </label>
          ))}
          <span className="text-xs text-muted-foreground">(ninguno = todos)</span>
        </div>
        {categories && categories.adminCategories.length > 0 && (
          <div className="flex max-h-28 flex-wrap gap-x-3 gap-y-1 overflow-auto text-sm">
            <span className="text-muted-foreground">Solo estas categorías:</span>
            {categories.adminCategories.map((c) => (
              <label key={c.id} className="flex items-center gap-1.5">
                <Checkbox checked={form.categoryIds.includes(c.id)} onCheckedChange={() => toggle('categoryIds', c.id)} />
                {c.translations[0]?.name}
              </label>
            ))}
          </div>
        )}
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={form.excludeSaleItems} onCheckedChange={(v) => setForm({ ...form, excludeSaleItems: v === true })} />
          No aplica a productos en oferta
        </label>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={form.firstOrderOnly} onCheckedChange={(v) => setForm({ ...form, firstOrderOnly: v === true })} />
          Solo en la primera compra
        </label>
      </fieldset>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isStackable} onCheckedChange={(v) => setForm({ ...form, isStackable: v === true })} />
        Se puede combinar con otras promociones y cupones acumulables
      </label>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isActive} onCheckedChange={(v) => setForm({ ...form, isActive: v === true })} />
        Activa
      </label>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}
