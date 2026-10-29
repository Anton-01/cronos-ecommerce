import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  AdminAttributesDocument,
  DeleteVariationDocument,
  GenerateVariationsDocument,
  PublishProductDocument,
  SetProductAttributesDocument,
  SetProductOfferedValuesDocument,
  UnpublishProductDocument,
  UpdateVariationDocument,
  type AdminAttributesQuery,
} from '@/graphql/generated'
import { useAuth } from '@/features/auth/AuthProvider'

import { useSupportedLocales } from '../shared'
import { CurrencyPrices } from './PricesTab'
import { STATUS_LABEL } from './ProductsPage'
import { type Product } from './tabs'
import { useRun } from './useRun'

type Attribute = AdminAttributesQuery['adminAttributes'][number]

export function VariationsTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const { data } = useQuery(AdminAttributesDocument)
  const attributes = data?.adminAttributes ?? []

  return (
    <div className="space-y-4">
      <AttributesCard product={product} attributes={attributes} canEdit={canEdit} />
      <VariationsCard product={product} attributes={attributes} canEdit={canEdit} />
    </div>
  )
}

function AttributesCard({ product, attributes, canEdit }: { product: Product; attributes: Attribute[]; canEdit: boolean }) {
  const run = useRun()
  const { defaultLocale } = useSupportedLocales()
  const [setAttrs, { loading: a }] = useMutation(SetProductAttributesDocument)
  const [setValues, { loading: b }] = useMutation(SetProductOfferedValuesDocument)
  const locked = product.variations.length > 0
  const [used, setUsed] = useState<Record<string, { isVariation: boolean; isVisible: boolean }>>(() => Object.fromEntries(product.attributes.map((x) => [x.attributeId, { isVariation: x.isVariation, isVisible: x.isVisible }])))
  const [offered, setOffered] = useState<string[]>(product.offeredValueIds)

  const label = (items: { locale: string; value: string | null }[]) => items.find((i) => i.locale === defaultLocale)?.value ?? items[0]?.value ?? ''

  const save = () =>
    run(async () => {
      await setAttrs({ variables: { id: product.id, attributes: Object.entries(used).map(([attributeId, v]) => ({ attributeId, ...v })) } })
      const allowed = new Set(attributes.filter((x) => used[x.id]).flatMap((x) => x.values.map((v) => v.id)))
      await setValues({ variables: { id: product.id, valueIds: offered.filter((id) => allowed.has(id)) } })
    }, 'Atributos guardados')

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Atributos de variación</CardTitle>
        <CardDescription>
          Elige qué atributos distinguen las variaciones (p. ej. Color y Ancho) y qué valores ofreces.
          {locked && ' Con variaciones creadas, los atributos de variación ya no se pueden cambiar; sí puedes añadir valores.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {attributes.length === 0 && <p className="text-sm text-muted-foreground">Primero crea atributos en Catálogo → Atributos.</p>}
        {attributes.map((attr) => {
          const on = used[attr.id]
          return (
            <div key={attr.id} className="space-y-2 rounded-lg border p-3">
              <div className="flex flex-wrap items-center gap-4">
                <div className="flex items-center gap-2">
                  <Checkbox
                    id={`use-${attr.id}`}
                    checked={!!on}
                    disabled={!canEdit || (locked && !!on?.isVariation)}
                    onCheckedChange={(v) => setUsed((u) => (v === true ? { ...u, [attr.id]: { isVariation: true, isVisible: true } } : Object.fromEntries(Object.entries(u).filter(([k]) => k !== attr.id))))}
                  />
                  <Label htmlFor={`use-${attr.id}`} className="font-medium">
                    {label(attr.names)}
                  </Label>
                </div>
                {on && (
                  <div className="flex items-center gap-2 text-sm">
                    <Checkbox id={`var-${attr.id}`} checked={on.isVariation} disabled={!canEdit || locked} onCheckedChange={(v) => setUsed((u) => ({ ...u, [attr.id]: { ...on, isVariation: v === true } }))} />
                    <Label htmlFor={`var-${attr.id}`} className="font-normal">
                      Define variaciones
                    </Label>
                  </div>
                )}
              </div>
              {on && (
                <div className="flex flex-wrap gap-x-4 gap-y-1.5 pl-6">
                  {attr.values.map((v) => (
                    <div key={v.id} className="flex items-center gap-1.5">
                      <Checkbox id={`val-${v.id}`} checked={offered.includes(v.id)} disabled={!canEdit} onCheckedChange={(c) => setOffered((o) => (c === true ? [...o, v.id] : o.filter((x) => x !== v.id)))} />
                      {attr.type === 'color' && typeof (v.meta as { hex?: string } | null)?.hex === 'string' && <span aria-hidden className="size-3.5 rounded-full border" style={{ backgroundColor: (v.meta as { hex: string }).hex }} />}
                      <Label htmlFor={`val-${v.id}`} className="font-normal">
                        {label(v.labels)}
                      </Label>
                    </div>
                  ))}
                  {attr.values.length === 0 && <span className="text-sm text-muted-foreground">Este atributo aún no tiene valores.</span>}
                </div>
              )}
            </div>
          )
        })}
        {canEdit && (
          <div className="flex justify-end">
            <Button disabled={a || b} onClick={() => void save()}>
              Guardar atributos
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}

function VariationsCard({ product, attributes, canEdit }: { product: Product; attributes: Attribute[]; canEdit: boolean }) {
  const run = useRun()
  const { can } = useAuth()
  const { defaultLocale } = useSupportedLocales()
  const [generate, { loading }] = useMutation(GenerateVariationsDocument)
  const valueLabel = new Map(attributes.flatMap((a) => a.values.map((v) => [v.id, v.labels.find((l) => l.locale === defaultLocale)?.value ?? v.code] as const)))
  const canGenerate = canEdit && product.attributes.some((x) => x.isVariation) && product.offeredValueIds.length > 0

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-3">
        <div className="space-y-1.5">
          <CardTitle className="text-base">Variaciones ({product.variations.length})</CardTitle>
          <CardDescription>Cada una hereda el precio, peso y medidas del producto, salvo que le pongas uno propio.</CardDescription>
        </div>
        {canGenerate && (
          <Button
            variant="outline"
            disabled={loading}
            onClick={() =>
              void run(async () => {
                const before = product.variations.length
                const { data } = await generate({ variables: { parentId: product.id } })
                const created = (data?.generateVariations.variations.length ?? before) - before
                if (created === 0) throw new Error('nothing new')
              }, 'Combinaciones generadas')
            }
          >
            Generar combinaciones
          </Button>
        )}
      </CardHeader>
      <CardContent className="space-y-2">
        {product.variations.length === 0 && <p className="text-sm text-muted-foreground">Aún no hay variaciones. Marca los atributos y valores, guarda, y genera las combinaciones.</p>}
        {product.variations.map((v) => (
          <VariationRow key={`${v.id}-${v.sku}-${v.price}`} product={product} variation={v} label={v.valueIds.map((id) => valueLabel.get(id) ?? '?').join(' · ')} canEdit={canEdit} canPublish={can('catalog.publish')} />
        ))}
      </CardContent>
    </Card>
  )
}

function VariationRow({ product, variation: v, label, canEdit, canPublish }: { product: Product; variation: Product['variations'][number]; label: string; canEdit: boolean; canPublish: boolean }) {
  const run = useRun()
  const [sku, setSku] = useState(v.sku ?? '')
  const [price, setPrice] = useState(v.price ?? '')
  const [update] = useMutation(UpdateVariationDocument)
  const [remove] = useMutation(DeleteVariationDocument)
  const [publish] = useMutation(PublishProductDocument, { refetchQueries: ['AdminProduct'] })
  const [unpublish] = useMutation(UnpublishProductDocument, { refetchQueries: ['AdminProduct'] })
  const dirty = sku !== (v.sku ?? '') || price !== (v.price ?? '')
  const published = v.status === 'PUBLISHED'
  const [showPrices, setShowPrices] = useState(false)
  const { can: canSee } = useAuth()

  return (
    <div className="space-y-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">{label}</span>
        <Badge variant={published ? 'success' : 'secondary'}>{STATUS_LABEL[v.status]}</Badge>
        {v.overridden.length > 0 && <Badge variant="outline">Propio: {v.overridden.join(', ')}</Badge>}
        <span className="ml-auto text-xs text-muted-foreground">Existencias: {Number(v.stockQuantity)}</span>
      </div>
      <div className="grid gap-2 sm:grid-cols-[1fr_1fr_auto]">
        <div className="space-y-1">
          <Label htmlFor={`vsku-${v.id}`} className="text-xs">
            SKU
          </Label>
          <Input id={`vsku-${v.id}`} value={sku} disabled={!canEdit} onChange={(e) => setSku(e.target.value)} />
        </div>
        <div className="space-y-1">
          <Label htmlFor={`vprice-${v.id}`} className="text-xs">
            Precio propio <span className="text-muted-foreground">(hereda {v.effectivePrice ?? '—'})</span>
          </Label>
          <Input id={`vprice-${v.id}`} inputMode="decimal" placeholder={v.effectivePrice ?? ''} value={price} disabled={!canEdit} onChange={(e) => setPrice(e.target.value)} />
        </div>
        <div className="flex items-end gap-1">
          {canEdit && (
            <Button size="sm" disabled={!dirty} onClick={() => void run(() => update({ variables: { id: v.id, input: { sku: sku.trim(), price: price.trim() || null } } }), 'Variación guardada')}>
              Guardar
            </Button>
          )}
          {canPublish && (
            <Button size="sm" variant="outline" onClick={() => void run(() => (published ? unpublish({ variables: { id: v.id, status: 'INACTIVE' } }) : publish({ variables: { id: v.id } })), published ? 'Variación oculta' : 'Variación publicada')}>
              {published ? 'Ocultar' : 'Publicar'}
            </Button>
          )}
          {canEdit && (
            <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: v.id }, refetchQueries: ['AdminProduct'] }), 'Variación eliminada')}>
              Eliminar
            </Button>
          )}
        </div>
      </div>
      {canSee('pricing.view') && (
        <div>
          <Button size="sm" variant="ghost" aria-expanded={showPrices} onClick={() => setShowPrices((s) => !s)}>
            {showPrices ? 'Ocultar precios por moneda' : 'Precios por moneda y ofertas'}
          </Button>
          {showPrices && (
            <div className="mt-2">
              <CurrencyPrices productId={v.id} basePrice={v.effectivePrice} sellsByRoll={product.sales.sellByRoll} canEdit={canEdit} />
            </div>
          )}
        </div>
      )}
      {!published && v.publishProblems.length > 0 && <p className="text-xs text-muted-foreground">Para publicar: {v.publishProblems.join('; ')}.</p>}
      <span className="sr-only">{product.id}</span>
    </div>
  )
}
