import { useMutation } from '@apollo/client/react'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { CreateProductDocument, type ProductType } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'
import { cn } from '@/lib/utils'

import { LocaleInputs, PageHeader, useSupportedLocales } from '../shared'
import { SalesUnitFields } from './SalesUnitFields'
import { PIECES, RIBBON, toSalesInput, validateSales, type SalesForm } from './salesUnit'

const TYPES: { value: Exclude<ProductType, 'VARIATION'>; title: string; text: string }[] = [
  { value: 'SIMPLE', title: 'Simple', text: 'Un producto con un solo precio (un listón, un moño).' },
  { value: 'VARIABLE', title: 'Con variaciones', text: 'Mismo producto en varios colores, anchos o materiales.' },
  { value: 'GROUPED', title: 'Agrupado', text: 'Un kit que reúne otros productos.' },
]

export function CreateProductPage() {
  const navigate = useNavigate()
  const { locales, defaultLocale } = useSupportedLocales()
  const [type, setType] = useState<Exclude<ProductType, 'VARIATION'>>('SIMPLE')
  const [names, setNames] = useState<Record<string, string>>({})
  const [sku, setSku] = useState('')
  const [price, setPrice] = useState('')
  const [sales, setSales] = useState<SalesForm>(PIECES)
  const [create, { loading }] = useMutation(CreateProductDocument)

  const grouped = type === 'GROUPED'
  const valid = (names[defaultLocale] ?? '').trim() !== '' && (grouped || validateSales(sales).length === 0)

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      const { data } = await create({
        variables: {
          input: {
            type,
            sku: sku.trim() || null,
            price: grouped || type === 'VARIABLE' ? null : price.trim() || null,
            sales: grouped ? null : toSalesInput(sales),
            translations: locales.filter((l) => (names[l.code] ?? '').trim()).map((l) => ({ locale: l.code, name: names[l.code]!.trim() })),
          },
        },
      })
      toast.success('Producto creado como borrador')
      if (data) navigate(`/catalog/products/${data.createProduct.id}`)
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="max-w-3xl space-y-6">
      <PageHeader title="Nuevo producto" description="Se crea como borrador: lo completas y lo publicas cuando esté listo." />

      <fieldset className="grid gap-3 sm:grid-cols-3">
        <legend className="sr-only">Tipo de producto</legend>
        {TYPES.map((t) => (
          <label key={t.value} className={cn('cursor-pointer rounded-xl border p-3 text-sm transition-colors', type === t.value ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'hover:bg-accent')}>
            <input type="radio" name="type" className="sr-only" checked={type === t.value} onChange={() => { setType(t.value); setSales(t.value === 'GROUPED' ? PIECES : sales) }} />
            <div className="font-medium">{t.title}</div>
            <div className="text-muted-foreground">{t.text}</div>
          </label>
        ))}
      </fieldset>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Datos básicos</CardTitle>
          <CardDescription>El nombre del idioma principal es obligatorio; la URL se genera sola.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-4">
          <LocaleInputs label="Nombre" locales={locales} values={names} onChange={setNames} required />
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="new-sku">SKU</Label>
              <Input id="new-sku" value={sku} maxLength={64} onChange={(e) => setSku(e.target.value)} />
            </div>
            {!grouped && type === 'SIMPLE' && (
              <div className="space-y-1.5">
                <Label htmlFor="new-price">{sales.unit === 'METER' ? 'Precio por metro' : 'Precio'}</Label>
                <Input id="new-price" inputMode="decimal" value={price} onChange={(e) => setPrice(e.target.value)} />
              </div>
            )}
          </div>
        </CardContent>
      </Card>

      {!grouped && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Cómo se vende</CardTitle>
            <CardDescription>Los listones pueden venderse por corte libre, por rollo o de las dos formas.</CardDescription>
          </CardHeader>
          <CardContent>
            <SalesUnitFields value={sales} onChange={setSales} price={price} />
            {sales.unit === 'PIECE' && (
              <Button type="button" variant="link" className="mt-2 px-0" onClick={() => setSales({ ...RIBBON, sellByRoll: true, rollLength: '25' })}>
                ¿Es un listón? Configurar corte y rollo
              </Button>
            )}
          </CardContent>
        </Card>
      )}

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={() => navigate('/catalog/products')}>
          Cancelar
        </Button>
        <Button type="submit" disabled={!valid || loading}>
          Crear producto
        </Button>
      </div>
    </form>
  )
}
