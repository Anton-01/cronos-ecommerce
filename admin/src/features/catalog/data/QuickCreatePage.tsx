import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { Check, Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { isValidQty } from '@/features/inventory/quantity'
import { Field } from '@/features/pricing/CurrenciesPage'
import { LocationSelect } from '@/features/warehouse/LocationSelect'
import { newKey } from '@/features/warehouse/lib'
import { useWarehouse } from '@/features/warehouse/useWarehouse'
import { AddAttributeValueDocument, AdminAttributesDocument, AdminCategoriesDocument, AdminProductTypesDocument, AdminSpecFieldsDocument, QuickCreateProductDocument, WhReceiveDocument, type AdminAttributesQuery, type QuickCreateProductMutation } from '@/graphql/generated'
import { cn } from '@/lib/utils'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { PageHeader, useSupportedLocales } from '../shared'
import { MAX_VARIANTS, cartesian, comboKey } from './combos'
import { SpecForm, ColorChips } from './SpecForm'
import { codeFrom, labelOf, missingRequired, toPayload, type States, type Values } from './specs'
import { useLists } from './useLists'

type Attribute = AdminAttributesQuery['adminAttributes'][number]
interface Variant {
  supplierCode: string
  price: string
  quantity: string
}

/** Registering a product the way the client thinks about it: pick the kind, describe it, tick the colours and widths, and every combination gets its own SKU. */
export function QuickCreatePage() {
  const { can } = useAuth()
  const client = useApolloClient()
  const { locales, defaultLocale } = useSupportedLocales()
  const lists = useLists()
  const { current } = useWarehouse()
  const { data: typeData } = useQuery(AdminProductTypesDocument, { variables: { includeInactive: false } })
  const { data: fieldData } = useQuery(AdminSpecFieldsDocument)
  const { data: cats } = useQuery(AdminCategoriesDocument)
  const [addValue] = useMutation(AddAttributeValueDocument)
  const [quick] = useMutation(QuickCreateProductDocument)
  const [receive] = useMutation(WhReceiveDocument)

  const [typeId, setTypeId] = useState('')
  const [names, setNames] = useState<Record<string, string>>({})
  const [price, setPrice] = useState('')
  const [rollPrice, setRollPrice] = useState('')
  const [categoryIds, setCategoryIds] = useState<string[]>([])
  const [shortDescription, setShortDescription] = useState('')
  const [values, setValues] = useState<Values>({})
  const [states, setStates] = useState<States>({})
  const [picked, setPicked] = useState<Record<string, string[]>>({})
  const [productPicked, setProductPicked] = useState<Record<string, string[]>>({})
  const [variants, setVariants] = useState<Record<string, Variant>>({})
  const [simple, setSimple] = useState<Variant>({ supplierCode: '', price: '', quantity: '' })
  const [location, setLocation] = useState('')
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<QuickCreateProductMutation['quickCreateProduct'] | null>(null)

  const type = typeData?.adminProductTypes.find((t) => t.id === typeId)
  const defs = fieldData?.adminSpecFields ?? []
  const required = type?.requiredFields ?? []
  const canStock = can('warehouse.operate') && current !== null
  const attribute = (id: string): Attribute | undefined => lists.attributes.find((a) => a.id === id)

  const axes = (type?.variantAttributeIds ?? []).filter((id) => (picked[id] ?? []).length > 0)
  const combos = cartesian(axes.map((id) => picked[id] ?? []))
  const tooMany = combos.length > MAX_VARIANTS
  const missing = missingRequired(required, values, states)
  const sellsByRoll = Boolean(values.presentation_unit) && Boolean(lists.byCatalog.unit?.find((u) => u.code === values.presentation_unit && (u.meta as { roll?: boolean } | null)?.roll))
  const valid = type !== undefined && (names[defaultLocale] ?? '').trim() !== '' && !tooMany

  const change = (key: string, v: unknown) => setValues((x) => ({ ...x, [key]: v }))
  const setState = (key: string, s: 'pending' | 'not_applicable') => setStates((x) => ({ ...x, [key]: x[key] === s ? 'pending' : s }))
  const group = (g: string) => defs.filter((d) => d.group === g && d.scope === 'product')
  const variantOf = (ids: string[]) => variants[comboKey(ids)] ?? { supplierCode: '', price: '', quantity: '' }
  const patchVariant = (ids: string[], p: Partial<Variant>) => setVariants((v) => ({ ...v, [comboKey(ids)]: { ...variantOf(ids), ...p } }))

  const createValue = async (attr: Attribute, label: string, hex?: string) => {
    const code = codeFrom(label)
    // Typing a value that is already in the list just picks it.
    const existing = attr.values.find((v) => v.code === code)
    if (existing) return existing.id
    if (!code) {
      toast.error('Escribe un nombre.')
      return undefined
    }
    try {
      const { data } = await addValue({ variables: { attributeId: attr.id, code, meta: attr.type === 'color' ? { hex: hex ?? '#888888' } : null, labels: locales.map((l) => ({ locale: l.code, value: label })) } })
      const value = data?.addAttributeValue.values.find((v) => v.code === code)
      await client.refetchQueries({ include: [AdminAttributesDocument] })
      return value?.id
    } catch (e) {
      toast.error(friendlyMessage(parseApiError(e)))
      return undefined
    }
  }

  const submit = async () => {
    if (!type || !valid) return
    setBusy(true)
    try {
      const keys = defs.filter((d) => d.scope === 'product').map((d) => d.key)
      const { specs, states: st } = toPayload(keys, values, states)
      const { data } = await quick({
        variables: {
          input: {
            typeId: type.id,
            names: locales.filter((l) => (names[l.code] ?? '').trim()).map((l) => ({ locale: l.code, value: names[l.code]!.trim() })),
            shortDescription: shortDescription.trim() ? [{ locale: defaultLocale, value: shortDescription.trim() }] : null,
            categoryIds, price: price.trim() || null, rollPrice: sellsByRoll ? rollPrice.trim() || null : null,
            specs: Object.fromEntries(Object.entries(specs).filter(([, v]) => v !== null)), states: st,
            variantValues: Object.fromEntries(axes.map((id) => [id, picked[id]])),
            productValues: productPicked,
            variants: combos.map((ids) => ({ valueIds: ids, price: variantOf(ids).price.trim() || null, supplierCode: variantOf(ids).supplierCode.trim() || null })),
            sku: null,
          },
        },
      })
      const result = data!.quickCreateProduct
      // Opening stock: each variation goes to the chosen shelf with its own key, so a retry never doubles it.
      if (canStock && current) {
        const lines = result.variations.length === 0 ? [{ id: result.productId, quantity: simple.quantity }] : result.variations.map((v) => ({ id: v.id, quantity: variantOf(v.valueIds).quantity }))
        for (const l of lines.filter((x) => isValidQty(x.quantity))) {
          await receive({ variables: { input: { productId: l.id, warehouseId: current.id, quantity: l.quantity.trim(), locationId: location || null, note: 'Existencia inicial al dar de alta', idempotencyKey: newKey() } } })
        }
      }
      setDone(result)
      toast.success('Producto creado como borrador')
    } catch (e) {
      toast.error(friendlyMessage(parseApiError(e)))
    } finally {
      setBusy(false)
    }
  }

  if (done) return <Done result={done} onAgain={() => { setDone(null); setNames({}); setValues({}); setStates({}); setPicked({}); setVariants({}) }} />

  return (
    <form onSubmit={(e) => { e.preventDefault(); void submit() }} className="max-w-5xl space-y-5 pb-24">
      <PageHeader title="Alta rápida de producto" description="Elige el tipo, descríbelo y marca los colores y anchos: cada combinación se crea con su propio SKU y su código de almacén. Si no conoces un dato, márcalo como pendiente: no lo inventes." />

      <Section title="Tipo de producto" description="Define qué datos son obligatorios y qué genera las variantes.">
        <div className="grid gap-2 sm:grid-cols-3" role="radiogroup" aria-label="Tipo de producto">
          {(typeData?.adminProductTypes ?? []).map((t) => (
            <button key={t.id} type="button" role="radio" aria-checked={typeId === t.id} onClick={() => { setTypeId(t.id); setPicked({}); setProductPicked({}) }} className={cn('rounded-lg border p-4 text-left transition-colors', typeId === t.id ? 'border-primary bg-primary/5' : 'hover:bg-accent')}>
              <span className="flex items-center justify-between font-medium">
                {labelOf(t.names, defaultLocale)}
                {typeId === t.id && <Check className="size-4 text-primary" aria-hidden />}
              </span>
              <span className="text-xs text-muted-foreground">SKU {t.skuPrefix}-00001 · {t.requiredFields.length} datos obligatorios</span>
            </button>
          ))}
        </div>
        {(typeData?.adminProductTypes ?? []).length === 0 && <p className="text-sm text-muted-foreground">Aún no hay tipos. Créalos en Catálogo → Tipos de producto.</p>}
      </Section>

      {type && (
        <>
          <Section title="Datos generales">
            <div className="grid gap-4 md:grid-cols-2">
              {locales.map((l) => (
                <Field key={l.code} id={`q-name-${l.code}`} label={`Nombre (${l.code})${l.isDefault ? ' *' : ''}`}>
                  <Input id={`q-name-${l.code}`} value={names[l.code] ?? ''} maxLength={255} onChange={(e) => setNames({ ...names, [l.code]: e.target.value })} />
                </Field>
              ))}
              <Field id="q-price" label="Precio base">
                <Input id="q-price" inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value)} />
              </Field>
              {sellsByRoll && (
                <Field id="q-roll" label="Precio del rollo completo (opcional)">
                  <Input id="q-roll" inputMode="decimal" value={rollPrice} onChange={(e) => setRollPrice(e.target.value)} />
                </Field>
              )}
            </div>
            <fieldset className="space-y-1">
              <legend className="text-sm font-medium">Categorías</legend>
              <div className="grid gap-1 sm:grid-cols-2">
                {(cats?.adminCategories ?? []).map((c) => (
                  <label key={c.id} className="flex items-center gap-2 text-sm" style={{ paddingLeft: `${(c.depth - 1) * 1.25}rem` }}>
                    <Checkbox checked={categoryIds.includes(c.id)} onCheckedChange={(on) => setCategoryIds((l) => (on === true ? [...l, c.id] : l.filter((x) => x !== c.id)))} />
                    {c.translations.find((t) => t.locale === defaultLocale)?.name ?? c.translations[0]?.name}
                  </label>
                ))}
              </div>
            </fieldset>
          </Section>

          <Section title="Características" description="Aplican a todas las variantes. Si no conoces un dato, márcalo como pendiente: no lo inventes.">
            <SpecForm fields={group('features')} values={values} states={states} required={required} lists={lists} onChange={change} onState={setState} />
          </Section>

          <Section title="Especificaciones del SKU" description="Tipo de empaque, peso y medidas del producto. La ubicación y la cantidad se capturan al final.">
            <SpecForm fields={group('sku')} values={values} states={states} required={required} lists={lists} onChange={change} onState={setState} />
          </Section>

          <Section title="Variante y unidad" description="Cada variante recibe su propio SKU. La ubicación no crea variantes nuevas.">
            <SpecForm fields={group('unit')} values={values} states={states} required={required} lists={lists} onChange={change} onState={setState} />
            {type.variantAttributeIds.map((id) => {
              const a = attribute(id)
              return a ? <Axis key={id} attr={a} lists={lists} value={picked[id] ?? []} onChange={(v) => setPicked({ ...picked, [id]: v })} onCreate={(label, hex) => createValue(a, label, hex)} /> : null
            })}
            {type.productAttributeIds.map((id) => {
              const a = attribute(id)
              return a ? <Axis key={id} attr={a} lists={lists} product value={productPicked[id] ?? []} onChange={(v) => setProductPicked({ ...productPicked, [id]: v })} onCreate={(label, hex) => createValue(a, label, hex)} /> : null
            })}

            {combos.length > 0 && (
              <div className="space-y-2">
                <h3 className="text-sm font-medium">
                  {combos.length} variante{combos.length === 1 ? '' : 's'} {tooMany && <span className="text-destructive">(el máximo es {MAX_VARIANTS}: quita valores)</span>}
                </h3>
                <div className="overflow-x-auto rounded-lg border">
                  <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left text-xs">
                      <tr>
                        <th className="px-3 py-2">Variante</th>
                        <th className="px-3 py-2">SKU</th>
                        <th className="px-3 py-2">Precio propio</th>
                        <th className="px-3 py-2">Código de proveedor</th>
                        {canStock && <th className="px-3 py-2">Existencia inicial</th>}
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {combos.map((ids) => (
                        <tr key={comboKey(ids)}>
                          <td className="px-3 py-2 font-medium">{ids.map((i) => valueLabel(lists.attributes, i, defaultLocale)).join(' · ')}</td>
                          <td className="px-3 py-2 text-xs text-muted-foreground">{type.skuPrefix}-… (al guardar)</td>
                          <td className="px-3 py-2"><Input aria-label={`Precio de ${ids.join(' ')}`} className="h-9 w-24" inputMode="decimal" value={variantOf(ids).price} onChange={(e) => patchVariant(ids, { price: e.target.value })} placeholder={price || '—'} /></td>
                          <td className="px-3 py-2"><Input aria-label={`Código de proveedor de ${ids.join(' ')}`} className="h-9 w-36" value={variantOf(ids).supplierCode} onChange={(e) => patchVariant(ids, { supplierCode: e.target.value })} /></td>
                          {canStock && <td className="px-3 py-2"><Input aria-label={`Existencia de ${ids.join(' ')}`} className="h-9 w-24" inputMode="decimal" value={variantOf(ids).quantity} onChange={(e) => patchVariant(ids, { quantity: e.target.value })} /></td>}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </Section>

          <Section title="Información adicional">
            <Field id="q-short" label="Descripción breve">
              <Textarea id="q-short" rows={2} value={shortDescription} maxLength={500} onChange={(e) => setShortDescription(e.target.value)} />
            </Field>
            <SpecForm fields={group('extra')} values={values} states={states} required={required} lists={lists} onChange={change} onState={setState} />
          </Section>

          {canStock && (
            <Section title="Existencia inicial (opcional)" description="Si ya tienes piezas, indica en qué anaquel quedan. Sin ubicación entran a Recepción, por acomodar.">
              <Field id="q-loc" label="Ubicación">
                <LocationSelect id="q-loc" warehouseId={current!.id} value={location} onChange={setLocation} allowEmpty hideReception />
              </Field>
              {combos.length === 0 && (
                <Field id="q-qty" label="Cantidad">
                  <Input id="q-qty" className="w-32" inputMode="decimal" value={simple.quantity} onChange={(e) => setSimple({ ...simple, quantity: e.target.value })} />
                </Field>
              )}
              {combos.length > 0 && <p className="text-sm text-muted-foreground">La cantidad de cada variante se escribe en la tabla de arriba.</p>}
            </Section>
          )}
        </>
      )}

      <div className="fixed inset-x-0 bottom-0 z-20 border-t bg-card p-3 md:left-60">
        <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-muted-foreground" role="status">
            {type ? (missing.length > 0 ? `Pendiente (no impide guardar, sí publicar): ${missing.map((k) => defs.find((d) => d.key === k)?.label).join(', ')}` : 'Datos obligatorios completos.') : 'Elige un tipo de producto para empezar.'}
          </p>
          <Button type="submit" size="lg" disabled={!valid || busy}>
            {busy ? 'Guardando…' : combos.length > 1 ? `Crear producto y ${combos.length} variantes` : 'Crear producto'}
          </Button>
        </div>
      </div>
    </form>
  )
}

const valueLabel = (attrs: Attribute[], id: string, locale: string): string => {
  for (const a of attrs) {
    const v = a.values.find((x) => x.id === id)
    if (v) return labelOf(v.labels, locale)
  }
  return id
}

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">{title}</CardTitle>
        {description && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  )
}

/** One attribute: tick its values; a value that does not exist yet is added to the list on the spot. */
function Axis({ attr, lists, value, product, onChange, onCreate }: { attr: Attribute; lists: ReturnType<typeof useLists>; value: string[]; product?: boolean; onChange: (v: string[]) => void; onCreate: (label: string, hex?: string) => Promise<string | undefined> }) {
  const [text, setText] = useState('')
  const [hex, setHex] = useState('#888888')
  const name = labelOf(attr.names, lists.defaultLocale)
  const isColor = attr.type === 'color'

  return (
    <div className="space-y-2">
      <Label>
        {name} <span className="font-normal text-muted-foreground">{product ? '(describe el producto)' : '(genera variantes)'}</span>
      </Label>
      {isColor ? (
        <ColorChips label={name} lists={{ ...lists, colors: attr.values }} value={value} onChange={onChange} />
      ) : (
        <div className="flex flex-wrap gap-2" role="group" aria-label={name}>
          {attr.values.map((v) => {
            const on = value.includes(v.id)
            return (
              <button key={v.id} type="button" aria-pressed={on} onClick={() => onChange(on ? value.filter((x) => x !== v.id) : [...value, v.id])} className={cn('rounded-full border px-3 py-1 text-sm transition-colors', on ? 'border-primary bg-primary/10 font-medium' : 'hover:bg-accent')}>
                {labelOf(v.labels, lists.defaultLocale)}
              </button>
            )
          })}
        </div>
      )}
      <div className="flex items-center gap-2">
        <Input aria-label={`Agregar ${name} nuevo`} className="h-9 max-w-52" value={text} placeholder={`Agregar ${name.toLowerCase()} nuevo…`} onChange={(e) => setText(e.target.value)} />
        {isColor && <input type="color" aria-label="Color nuevo" className="h-9 w-10 rounded border" value={hex} onChange={(e) => setHex(e.target.value)} />}
        <Button type="button" variant="outline" size="sm" disabled={text.trim() === ''} onClick={() => void onCreate(text.trim(), hex).then((id) => { if (id) { onChange(value.includes(id) ? value : [...value, id]); setText('') } })}>
          <Plus className="size-4" /> Agregar
        </Button>
      </div>
    </div>
  )
}

function Done({ result, onAgain }: { result: QuickCreateProductMutation['quickCreateProduct']; onAgain: () => void }) {
  return (
    <div className="mx-auto max-w-2xl space-y-4 rounded-xl border p-6 text-center">
      <Check className="mx-auto size-10 text-success" aria-hidden />
      <h1 className="text-xl font-semibold">Producto creado como borrador</h1>
      <p className="text-sm text-muted-foreground">
        {result.variations.length > 0 ? `${result.variations.length} variantes con SKU ${result.variations[0]!.sku} a ${result.variations[result.variations.length - 1]!.sku}.` : `SKU ${result.sku}.`} Cada una ya tiene su código de almacén para el QR.
      </p>
      <div className="flex flex-wrap justify-center gap-2">
        <Button asChild>
          <Link to={`/catalog/products/${result.productId}`}>Abrir el producto</Link>
        </Button>
        <Button asChild variant="outline">
          <Link to="/warehouse/labels">Imprimir etiquetas</Link>
        </Button>
        <Button variant="outline" onClick={onAgain}>
          Dar de alta otro
        </Button>
      </div>
    </div>
  )
}
