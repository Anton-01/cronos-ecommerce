import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/features/auth/AuthProvider'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { AdminAttributesDocument, AdminProductTypesDocument, AdminSpecFieldsDocument, CreateProductTypeDocument, DeleteProductTypeDocument, UpdateProductTypeDocument, type AdminProductTypesQuery } from '@/graphql/generated'

import { NativeSelect, PageHeader, useSupportedLocales } from '../shared'
import { useRun } from '../products/useRun'
import { codeFrom, labelOf } from './specs'

type PType = AdminProductTypesQuery['adminProductTypes'][number]
type Sales = { unit?: string; step?: string; min?: string; sell_by_cut?: boolean; sell_by_roll?: boolean }

/** Kinds of product: what the form requires, which attributes define the variants, how it is sold and how its SKUs start. */
export function TypesPage() {
  const { can } = useAuth()
  const run = useRun()
  const manage = can('catalog.manage')
  const { data, refetch } = useQuery(AdminProductTypesDocument, { variables: { includeInactive: true }, fetchPolicy: 'cache-and-network' })
  const [update] = useMutation(UpdateProductTypeDocument)
  const [remove] = useMutation(DeleteProductTypeDocument)
  const [editing, setEditing] = useState<PType | 'new' | null>(null)
  const { defaultLocale } = useSupportedLocales()

  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader
        title="Tipos de producto"
        description="Cada tipo (listón, pieza decorativa…) define qué datos son obligatorios, qué atributos generan las variantes y cómo empiezan sus SKU."
        actions={
          manage && (
            <Button onClick={() => setEditing('new')}>
              <Plus className="size-4" /> Nuevo tipo
            </Button>
          )
        }
      />
      <ul className="divide-y rounded-lg border">
        {(data?.adminProductTypes ?? []).map((t) => {
          const s = t.sales as Sales
          return (
            <li key={t.id} className={`flex flex-wrap items-center justify-between gap-3 px-4 py-3 ${t.isActive ? '' : 'opacity-60'}`}>
              <div className="min-w-0">
                <p className="font-medium">
                  {labelOf(t.names, defaultLocale)} <span className="font-mono text-xs text-muted-foreground">{t.code}</span>
                </p>
                <p className="flex flex-wrap gap-1.5 text-xs">
                  <Badge variant="secondary">SKU {t.skuPrefix}-00001</Badge>
                  <Badge variant="outline">{s.unit === 'meter' ? 'se vende por metro' : 'por pieza'}</Badge>
                  <Badge variant="outline">{t.requiredFields.length} datos obligatorios</Badge>
                  <Badge variant="outline">{t.variantAttributeIds.length} atributos de variante</Badge>
                  {!t.isActive && <Badge variant="destructive">Desactivado</Badge>}
                </p>
              </div>
              {manage && (
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" onClick={() => setEditing(t)}>
                    Editar
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => void run(() => update({ variables: { id: t.id, input: { isActive: !t.isActive } } }).then(() => refetch()), t.isActive ? 'Desactivado' : 'Activado')}>
                    {t.isActive ? 'Desactivar' : 'Activar'}
                  </Button>
                  <Button variant="ghost" size="sm" onClick={() => void run(() => remove({ variables: { id: t.id } }).then(() => refetch()), 'Eliminado')}>
                    Eliminar
                  </Button>
                </div>
              )}
            </li>
          )
        })}
      </ul>
      {editing && <TypeDialog key={editing === 'new' ? 'new' : editing.id} type={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onDone={() => void refetch()} />}
    </div>
  )
}

function TypeDialog({ type, onClose, onDone }: { type: PType | null; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const { locales, defaultLocale } = useSupportedLocales()
  const { data: fields } = useQuery(AdminSpecFieldsDocument)
  const { data: attrs } = useQuery(AdminAttributesDocument)
  const [create] = useMutation(CreateProductTypeDocument)
  const [update] = useMutation(UpdateProductTypeDocument)
  const s = (type?.sales ?? { unit: 'piece' }) as Sales
  const [names, setNames] = useState<Record<string, string>>(Object.fromEntries((type?.names ?? []).map((n) => [n.locale, n.value ?? ''])))
  const [code, setCode] = useState(type?.code ?? '')
  const [prefix, setPrefix] = useState(type?.skuPrefix ?? '')
  const [unit, setUnit] = useState(s.unit ?? 'piece')
  const [step, setStep] = useState(s.step ?? '0.5')
  const [min, setMin] = useState(s.min ?? '1')
  const [cut, setCut] = useState(s.sell_by_cut ?? true)
  const [roll, setRoll] = useState(s.sell_by_roll ?? false)
  const [required, setRequired] = useState<string[]>(type?.requiredFields ?? [])
  const [variantAttrs, setVariantAttrs] = useState<string[]>(type?.variantAttributeIds ?? [])
  const [productAttrs, setProductAttrs] = useState<string[]>(type?.productAttributeIds ?? [])

  const toggle = (list: string[], set: (v: string[]) => void, id: string, on: boolean) => set(on ? [...list, id] : list.filter((x) => x !== id))
  const valid = (names[defaultLocale] ?? '').trim() !== '' && /^[A-Za-z0-9]{1,8}$/.test(prefix) && (type !== null || /^[a-z0-9][a-z0-9_-]{1,31}$/.test(code))

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const input = {
      names: locales.filter((l) => (names[l.code] ?? '').trim()).map((l) => ({ locale: l.code, value: names[l.code]!.trim() })),
      skuPrefix: prefix.toUpperCase(),
      sales: unit === 'meter' ? { unit, step, min, sell_by_cut: cut || !roll, sell_by_roll: roll, roll_length: roll ? '25' : null } : { unit },
      requiredFields: required,
      variantAttributeIds: variantAttrs,
      productAttributeIds: productAttrs,
    }
    void run(() => (type ? update({ variables: { id: type.id, input } }) : create({ variables: { input: { ...input, code } } })), type ? 'Tipo actualizado' : 'Tipo creado').then((ok) => ok && (onDone(), onClose()))
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="max-w-2xl">
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>{type ? 'Editar tipo de producto' : 'Nuevo tipo de producto'}</DialogTitle>
            <DialogDescription>Los productos ya creados conservan sus datos; los cambios aplican a los que se den de alta o publiquen después.</DialogDescription>
          </DialogHeader>
          {locales.map((l) => (
            <Field key={l.code} id={`ty-${l.code}`} label={`Nombre (${l.code})`}>
              <Input
                id={`ty-${l.code}`}
                value={names[l.code] ?? ''}
                onChange={(e) => {
                  setNames({ ...names, [l.code]: e.target.value })
                  if (!type && l.isDefault) setCode(codeFrom(e.target.value).replace(/_/g, '-'))
                }}
              />
            </Field>
          ))}
          <div className="grid gap-4 sm:grid-cols-2">
            {!type && (
              <Field id="ty-code" label="Código">
                <Input id="ty-code" className="font-mono" value={code} onChange={(e) => setCode(e.target.value)} />
              </Field>
            )}
            <Field id="ty-prefix" label="Prefijo del SKU (ej. LIS)">
              <Input id="ty-prefix" className="font-mono uppercase" maxLength={8} value={prefix} onChange={(e) => setPrefix(e.target.value.toUpperCase())} />
            </Field>
          </div>
          <Field id="ty-unit" label="Cómo se vende">
            <NativeSelect id="ty-unit" value={unit} onChange={(e) => setUnit(e.target.value)}>
              <option value="piece">Por pieza</option>
              <option value="meter">Por metro (corte y/o rollo)</option>
            </NativeSelect>
          </Field>
          {unit === 'meter' && (
            <div className="grid gap-4 sm:grid-cols-4">
              <Field id="ty-step" label="Corte cada (m)">
                <Input id="ty-step" inputMode="decimal" value={step} onChange={(e) => setStep(e.target.value)} />
              </Field>
              <Field id="ty-min" label="Mínimo (m)">
                <Input id="ty-min" inputMode="decimal" value={min} onChange={(e) => setMin(e.target.value)} />
              </Field>
              <label className="flex items-end gap-2 pb-2 text-sm">
                <input type="checkbox" className="size-4" checked={cut} onChange={(e) => setCut(e.target.checked)} /> Por corte
              </label>
              <label className="flex items-end gap-2 pb-2 text-sm">
                <input type="checkbox" className="size-4" checked={roll} onChange={(e) => setRoll(e.target.checked)} /> Por rollo
              </label>
            </div>
          )}
          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">Datos obligatorios para publicar</legend>
            <p className="text-xs text-muted-foreground">«No aplica» cuenta como respuesta; «pendiente» no: el producto no se publica hasta resolverlo.</p>
            <div className="grid gap-1 sm:grid-cols-2">
              {(fields?.adminSpecFields ?? []).map((f) => (
                <label key={f.key} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" className="size-4" checked={required.includes(f.key)} onChange={(e) => toggle(required, setRequired, f.key, e.target.checked)} /> {f.label}
                </label>
              ))}
            </div>
          </fieldset>
          <div className="grid gap-4 sm:grid-cols-2">
            <fieldset className="space-y-1">
              <legend className="text-sm font-medium">Definen las variantes</legend>
              {(attrs?.adminAttributes ?? []).map((a) => (
                <label key={a.id} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" className="size-4" disabled={productAttrs.includes(a.id)} checked={variantAttrs.includes(a.id)} onChange={(e) => toggle(variantAttrs, setVariantAttrs, a.id, e.target.checked)} /> {labelOf(a.names, defaultLocale)}
                </label>
              ))}
            </fieldset>
            <fieldset className="space-y-1">
              <legend className="text-sm font-medium">Solo describen el producto</legend>
              {(attrs?.adminAttributes ?? []).map((a) => (
                <label key={a.id} className="flex items-center gap-2 text-sm">
                  <input type="checkbox" className="size-4" disabled={variantAttrs.includes(a.id)} checked={productAttrs.includes(a.id)} onChange={(e) => toggle(productAttrs, setProductAttrs, a.id, e.target.checked)} /> {labelOf(a.names, defaultLocale)}
                </label>
              ))}
            </fieldset>
          </div>
          <Actions onClose={onClose} disabled={!valid} />
        </form>
      </DialogContent>
    </Dialog>
  )
}
