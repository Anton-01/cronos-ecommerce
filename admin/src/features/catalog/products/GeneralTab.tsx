import { useMutation } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { UpdateProductDocument } from '@/graphql/generated'

import { useSupportedLocales } from '../shared'
import { type Product } from './tabs'
import { useRun } from './useRun'

type Dims = { length: string; width: string; height: string; unit: string }
type Text = { name: string; slug: string; shortDescription: string; description: string; metaTitle: string; metaDescription: string }

export function GeneralTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const { locales } = useSupportedLocales()
  const run = useRun()
  const [update, { loading }] = useMutation(UpdateProductDocument)
  const dims = (product.dimensions ?? {}) as Partial<Dims>

  const [text, setText] = useState<Record<string, Text>>(() =>
    Object.fromEntries(
      product.translations.map((t) => [t.locale, { name: t.name ?? '', slug: t.slug ?? '', shortDescription: t.shortDescription ?? '', description: t.description ?? '', metaTitle: t.metaTitle ?? '', metaDescription: t.metaDescription ?? '' }]),
    ),
  )
  const [sku, setSku] = useState(product.sku ?? '')
  const [barcode, setBarcode] = useState(product.barcode ?? '')
  const [weight, setWeight] = useState(product.weight ? String(Number(product.weight)) : '')
  const [size, setSize] = useState<Dims>({ length: dims.length ?? '', width: dims.width ?? '', height: dims.height ?? '', unit: dims.unit ?? 'cm' })
  const [manageStock, setManageStock] = useState(product.manageStock)
  const [backorder, setBackorder] = useState(product.allowBackorder)
  const [lowStock, setLowStock] = useState(product.lowStockThreshold ? String(Number(product.lowStockThreshold)) : '')
  const [custom, setCustom] = useState(product.isCustomizable)

  const field = (locale: string, key: keyof Text, value: string) => setText((t) => ({ ...t, [locale]: { name: '', slug: '', shortDescription: '', description: '', metaTitle: '', metaDescription: '', ...t[locale], [key]: value } }))
  const nullable = (v: string) => (v.trim() === '' ? null : v.trim())
  const hasDims = [size.length, size.width, size.height].some((v) => v.trim())

  const save = (e: React.FormEvent) => {
    e.preventDefault()
    return run(
      () =>
        update({
          variables: {
            id: product.id,
            input: {
              sku: nullable(sku),
              barcode: nullable(barcode),
              weight: nullable(weight),
              dimensions: hasDims ? { length: nullable(size.length), width: nullable(size.width), height: nullable(size.height), unit: size.unit } : null,
              manageStock,
              allowBackorder: backorder,
              lowStockThreshold: nullable(lowStock),
              isCustomizable: custom,
              translations: locales
                .filter((l) => (text[l.code]?.name ?? '').trim())
                .map((l) => {
                  const t = text[l.code]!
                  return { locale: l.code, name: t.name.trim(), slug: nullable(t.slug), shortDescription: nullable(t.shortDescription), description: nullable(t.description), metaTitle: nullable(t.metaTitle), metaDescription: nullable(t.metaDescription) }
                }),
            },
          },
        }),
      'Producto guardado',
    )
  }

  return (
    <form onSubmit={(e) => void save(e)} className="space-y-4">
      {locales.map((l) => (
        <Card key={l.code}>
          <CardHeader>
            <CardTitle className="text-base">Contenido · {l.code}</CardTitle>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Labeled id={`name-${l.code}`} label="Nombre">
                <Input id={`name-${l.code}`} value={text[l.code]?.name ?? ''} disabled={!canEdit} maxLength={255} required={l.isDefault} onChange={(e) => field(l.code, 'name', e.target.value)} />
              </Labeled>
              <Labeled id={`slug-${l.code}`} label="URL (slug)" hint="Vacío = se genera del nombre">
                <Input id={`slug-${l.code}`} value={text[l.code]?.slug ?? ''} disabled={!canEdit} onChange={(e) => field(l.code, 'slug', e.target.value)} />
              </Labeled>
            </div>
            <Labeled id={`short-${l.code}`} label="Descripción corta">
              <Textarea id={`short-${l.code}`} rows={2} value={text[l.code]?.shortDescription ?? ''} disabled={!canEdit} onChange={(e) => field(l.code, 'shortDescription', e.target.value)} />
            </Labeled>
            <Labeled id={`desc-${l.code}`} label="Descripción">
              <Textarea id={`desc-${l.code}`} rows={5} value={text[l.code]?.description ?? ''} disabled={!canEdit} onChange={(e) => field(l.code, 'description', e.target.value)} />
            </Labeled>
            <div className="grid gap-3 sm:grid-cols-2">
              <Labeled id={`mt-${l.code}`} label="Título SEO">
                <Input id={`mt-${l.code}`} value={text[l.code]?.metaTitle ?? ''} disabled={!canEdit} onChange={(e) => field(l.code, 'metaTitle', e.target.value)} />
              </Labeled>
              <Labeled id={`md-${l.code}`} label="Descripción SEO">
                <Input id={`md-${l.code}`} value={text[l.code]?.metaDescription ?? ''} maxLength={320} disabled={!canEdit} onChange={(e) => field(l.code, 'metaDescription', e.target.value)} />
              </Labeled>
            </div>
          </CardContent>
        </Card>
      ))}

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Identificación y envío</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-3">
            <Labeled id="sku" label="SKU" hint={product.type === 'VARIABLE' ? 'Opcional: cada variación tiene el suyo' : undefined}>
              <Input id="sku" value={sku} disabled={!canEdit} maxLength={64} onChange={(e) => setSku(e.target.value)} />
            </Labeled>
            <Labeled id="barcode" label="Código de barras">
              <Input id="barcode" value={barcode} disabled={!canEdit} onChange={(e) => setBarcode(e.target.value)} />
            </Labeled>
            <Labeled id="weight" label="Peso (kg por unidad de venta)" hint="Por metro en listones">
              <Input id="weight" inputMode="decimal" value={weight} disabled={!canEdit} onChange={(e) => setWeight(e.target.value)} />
            </Labeled>
          </div>
          <div className="grid gap-3 sm:grid-cols-4">
            {(['length', 'width', 'height'] as const).map((k) => (
              <Labeled key={k} id={`dim-${k}`} label={{ length: 'Largo', width: 'Ancho', height: 'Alto' }[k]}>
                <Input id={`dim-${k}`} inputMode="decimal" value={size[k]} disabled={!canEdit} onChange={(e) => setSize({ ...size, [k]: e.target.value })} />
              </Labeled>
            ))}
            <Labeled id="dim-unit" label="Unidad">
              <select id="dim-unit" className="h-9 w-full rounded-md border bg-background px-2 text-sm" value={size.unit} disabled={!canEdit} onChange={(e) => setSize({ ...size, unit: e.target.value })}>
                {['mm', 'cm', 'm'].map((u) => (
                  <option key={u}>{u}</option>
                ))}
              </select>
            </Labeled>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Inventario y pedidos</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <Check id="manage-stock" checked={manageStock} onChange={setManageStock} disabled={!canEdit} label="Controlar inventario" />
          <Check id="backorder" checked={backorder} onChange={setBackorder} disabled={!canEdit} label="Permitir pedidos sin existencias" />
          <Check id="custom" checked={custom} onChange={setCustom} disabled={!canEdit} label="Admite pedidos personalizados" />
          <div className="max-w-xs">
            <Labeled id="low-stock" label="Aviso de existencias bajas" hint="Se reportará cuando baje de esta cantidad">
              <Input id="low-stock" inputMode="decimal" value={lowStock} disabled={!canEdit} onChange={(e) => setLowStock(e.target.value)} />
            </Labeled>
          </div>
          <p className="text-xs text-muted-foreground">Existencias actuales: {Number(product.stockQuantity)} (se actualizan con el inventario, no desde aquí).</p>
        </CardContent>
      </Card>

      {canEdit && (
        <div className="flex justify-end">
          <Button type="submit" disabled={loading}>
            Guardar cambios
          </Button>
        </div>
      )}
    </form>
  )
}

export function Labeled({ id, label, hint, children }: { id: string; label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}

export function Check({ id, checked, onChange, label, disabled }: { id: string; checked: boolean; onChange: (v: boolean) => void; label: string; disabled?: boolean }) {
  return (
    <div className="flex items-center gap-2">
      <Checkbox id={id} checked={checked} disabled={disabled} onCheckedChange={(v) => onChange(v === true)} />
      <Label htmlFor={id}>{label}</Label>
    </div>
  )
}
