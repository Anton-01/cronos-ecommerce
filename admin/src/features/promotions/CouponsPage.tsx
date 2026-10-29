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
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import {
  AdminCategoriesDocument,
  AdminCouponsDocument,
  AdminCurrenciesDocument,
  AdminMarketsDocument,
  type CouponType,
  CreateCouponDocument,
  DeleteCouponDocument,
  UpdateCouponDocument,
  type AdminCouponsQuery,
} from '@/graphql/generated'

import { fromApiDate, toApiDate } from './dates'
import { ProductPicker } from './ProductPicker'

type Coupon = AdminCouponsQuery['adminCoupons'][number]

const TYPE_LABEL: Record<string, string> = { FIXED: 'Monto fijo', PERCENTAGE: 'Porcentaje', FREE_SHIPPING: 'Envío gratis' }

export const describeCoupon = (c: Pick<Coupon, 'type' | 'value' | 'currency'>) =>
  c.type === 'PERCENTAGE' ? `${Number(c.value)} %` : c.type === 'FIXED' ? `${Number(c.value)} ${c.currency ?? ''}` : 'Envío gratis'

export function CouponsPage() {
  const { can } = useAuth()
  const canManage = can('promotions.manage')
  const run = useRun()
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const { data, loading } = useQuery(AdminCouponsDocument, { variables: { search: debounced || null } })
  const [remove] = useMutation(DeleteCouponDocument, { refetchQueries: ['AdminCoupons'] })
  const [editing, setEditing] = useState<{ coupon: Coupon | null } | null>(null)

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader
        title="Cupones"
        description="Códigos que el cliente escribe en el carrito. El uso se descuenta de forma atómica al confirmar el pedido."
        actions={canManage && <Button onClick={() => setEditing({ coupon: null })}><Plus /> Nuevo cupón</Button>}
      />
      <Input type="search" aria-label="Buscar cupones" placeholder="Buscar por código o nombre…" value={search} onChange={(e) => setSearch(e.target.value)} />
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminCoupons.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-36 font-mono font-semibold">{c.code}</span>
            <span className="flex-1">
              {c.name} <span className="text-muted-foreground">· {describeCoupon(c)}</span>
            </span>
            <span className="text-sm tabular-nums text-muted-foreground">
              {c.usesCount}
              {c.maxUses ? ` / ${c.maxUses}` : ''} usos
            </span>
            {!c.isActive && <Badge variant="outline">Inactivo</Badge>}
            {c.isStackable && <Badge variant="secondary">Acumulable</Badge>}
            {canManage && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ coupon: c })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: c.id } }), 'Cupón eliminado')}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
        {data && data.adminCoupons.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin cupones.</li>}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">{editing && <CouponForm key={editing.coupon?.id ?? 'new'} coupon={editing.coupon} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function CouponForm({ coupon, onClose }: { coupon: Coupon | null; onClose: () => void }) {
  const run = useRun()
  const { data: currencies } = useQuery(AdminCurrenciesDocument)
  const { data: markets } = useQuery(AdminMarketsDocument)
  const { data: categories } = useQuery(AdminCategoriesDocument)
  const [create] = useMutation(CreateCouponDocument, { refetchQueries: ['AdminCoupons'] })
  const [update] = useMutation(UpdateCouponDocument, { refetchQueries: ['AdminCoupons'] })
  const [form, setForm] = useState({
    code: coupon?.code ?? '',
    name: coupon?.name ?? '',
    type: (coupon?.type ?? 'PERCENTAGE') as CouponType,
    value: coupon ? String(Number(coupon.value)) : '',
    currency: coupon?.currency ?? 'MXN',
    minSpend: coupon?.minSpend ?? '',
    maxDiscount: coupon?.maxDiscount ?? '',
    maxUses: coupon?.maxUses?.toString() ?? '',
    maxUsesPerUser: coupon?.maxUsesPerUser?.toString() ?? '',
    startsAt: fromApiDate(coupon?.startsAt),
    expiresAt: fromApiDate(coupon?.expiresAt),
    isStackable: coupon?.isStackable ?? false,
    isActive: coupon?.isActive ?? true,
    marketCodes: coupon?.restrictions.marketCodes ?? [],
    categoryIds: coupon?.restrictions.categoryIds ?? [],
    productIds: coupon?.restrictions.productIds ?? [],
    excludeSaleItems: coupon?.restrictions.excludeSaleItems ?? false,
    firstOrderOnly: coupon?.restrictions.firstOrderOnly ?? false,
  })
  const [productNames, setProductNames] = useState<Record<string, string>>({})
  const toggle = (key: 'marketCodes' | 'categoryIds', value: string) => setForm((f) => ({ ...f, [key]: f[key].includes(value) ? f[key].filter((v) => v !== value) : [...f[key], value] }))
  const needsValue = form.type !== 'FREE_SHIPPING'
  const valid = /^[A-Za-z0-9_-]{3,40}$/.test(form.code) && form.name.trim() && (!needsValue || Number(form.value) > 0) && (form.type !== 'PERCENTAGE' || Number(form.value) <= 100)

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const int = (v: string) => (v.trim() ? Number(v) : null)
    const input = {
      code: form.code.trim(),
      name: form.name.trim(),
      type: form.type,
      value: needsValue ? form.value : '0',
      currency: form.type === 'FIXED' ? form.currency : null,
      minSpend: form.minSpend.trim() || null,
      maxDiscount: form.type === 'PERCENTAGE' ? form.maxDiscount.trim() || null : null,
      maxUses: int(form.maxUses),
      maxUsesPerUser: int(form.maxUsesPerUser),
      isStackable: form.isStackable,
      isActive: form.isActive,
      startsAt: toApiDate(form.startsAt),
      expiresAt: toApiDate(form.expiresAt),
      restrictions: { marketCodes: form.marketCodes, categoryIds: form.categoryIds, productIds: form.productIds, excludeSaleItems: form.excludeSaleItems, firstOrderOnly: form.firstOrderOnly },
    }
    void run(() => (coupon ? update({ variables: { id: coupon.id, input } }) : create({ variables: { input } })), 'Cupón guardado').then((ok) => ok && onClose())
  }

  return (
    <form className="space-y-4" onSubmit={submit}>
      <DialogHeader>
        <DialogTitle>{coupon ? 'Editar cupón' : 'Nuevo cupón'}</DialogTitle>
        <DialogDescription>Las fechas están en hora UTC. Un cupón no acumulable compite con las promociones automáticas: gana el que más ahorra.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="cp-code" label="Código">
          <Input id="cp-code" maxLength={40} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="cp-name" label="Nombre interno">
          <Input id="cp-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="cp-type" label="Tipo">
          <NativeSelect id="cp-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as CouponType })}>
            {Object.entries(TYPE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </NativeSelect>
        </Field>
        {needsValue && (
          <Field id="cp-value" label={form.type === 'PERCENTAGE' ? 'Descuento (%)' : 'Monto'}>
            <Input id="cp-value" inputMode="decimal" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value })} />
          </Field>
        )}
        {form.type === 'FIXED' && (
          <Field id="cp-currency" label="Moneda">
            <NativeSelect id="cp-currency" value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value })}>
              {currencies?.adminCurrencies.map((c) => (
                <option key={c.id}>{c.code}</option>
              ))}
            </NativeSelect>
          </Field>
        )}
        {form.type === 'PERCENTAGE' && (
          <Field id="cp-max-discount" label="Descuento máximo (opcional)">
            <Input id="cp-max-discount" inputMode="decimal" value={form.maxDiscount} onChange={(e) => setForm({ ...form, maxDiscount: e.target.value })} />
          </Field>
        )}
        <Field id="cp-min" label="Compra mínima (opcional)">
          <Input id="cp-min" inputMode="decimal" value={form.minSpend} onChange={(e) => setForm({ ...form, minSpend: e.target.value })} />
        </Field>
        <Field id="cp-uses" label="Usos totales (opcional)">
          <Input id="cp-uses" inputMode="numeric" value={form.maxUses} onChange={(e) => setForm({ ...form, maxUses: e.target.value })} />
        </Field>
        <Field id="cp-user-uses" label="Usos por cliente (opcional)">
          <Input id="cp-user-uses" inputMode="numeric" value={form.maxUsesPerUser} onChange={(e) => setForm({ ...form, maxUsesPerUser: e.target.value })} />
        </Field>
        <Field id="cp-starts" label="Válido desde">
          <Input id="cp-starts" type="datetime-local" value={form.startsAt} onChange={(e) => setForm({ ...form, startsAt: e.target.value })} />
        </Field>
        <Field id="cp-expires" label="Vence">
          <Input id="cp-expires" type="datetime-local" value={form.expiresAt} onChange={(e) => setForm({ ...form, expiresAt: e.target.value })} />
        </Field>
      </div>

      <fieldset className="space-y-2 rounded-md border p-3">
        <legend className="px-1 text-xs font-medium text-muted-foreground">Restricciones</legend>
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
        <ProductPicker value={form.productIds} names={productNames} onChange={(ids, names) => { setForm((f) => ({ ...f, productIds: ids })); setProductNames(names) }} />
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
        Acumulable con promociones automáticas
      </label>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isActive} onCheckedChange={(v) => setForm({ ...form, isActive: v === true })} />
        Activo
      </label>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}
