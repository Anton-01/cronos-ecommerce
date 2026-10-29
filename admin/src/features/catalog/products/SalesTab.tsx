import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { useAuth } from '@/features/auth/AuthProvider'
import { AdminTaxClassesDocument, UpdateProductDocument } from '@/graphql/generated'

import { NativeSelect } from '../shared'

import { Labeled } from './GeneralTab'
import { SalesUnitFields } from './SalesUnitFields'
import { fromSalesApi, toSalesInput, validateSales, type SalesForm } from './salesUnit'
import { type Product } from './tabs'
import { useRun } from './useRun'

export function SalesTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const run = useRun()
  const { can } = useAuth()
  const { data: taxClasses } = useQuery(AdminTaxClassesDocument, { skip: !can('pricing.view') })
  const [taxClassId, setTaxClassId] = useState(product.taxClassId ?? '')
  const [update, { loading }] = useMutation(UpdateProductDocument)
  const [price, setPrice] = useState(product.price ?? '')
  const [sales, setSales] = useState<SalesForm>(() => fromSalesApi(product.sales))
  const variable = product.type === 'VARIABLE'
  const meter = sales.unit === 'METER'

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">{meter ? 'Precio por metro' : 'Precio'}</CardTitle>
          <CardDescription>
            {variable ? 'Precio base que heredan las variaciones; cada una puede tener el suyo.' : 'Precio en la moneda base. Otras monedas, precios tachados y ofertas están en la pestaña Precios.'}
          </CardDescription>
        </CardHeader>
        <CardContent className="max-w-xs space-y-3">
          <Labeled id="price" label={meter ? 'Precio por metro' : 'Precio'}>
            <Input id="price" inputMode="decimal" value={price} disabled={!canEdit} onChange={(e) => setPrice(e.target.value)} />
          </Labeled>
          {taxClasses && (
            <Labeled id="tax-class" label="Clase de impuesto">
              <NativeSelect id="tax-class" value={taxClassId} disabled={!canEdit} onChange={(e) => setTaxClassId(e.target.value)}>
                <option value="">Predeterminada</option>
                {taxClasses.adminTaxClasses.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </NativeSelect>
            </Labeled>
          )}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Cómo se vende</CardTitle>
          <CardDescription>Define cómo puede comprar el cliente: por pieza, por corte libre, por rollo o de ambas formas.</CardDescription>
        </CardHeader>
        <CardContent>
          <SalesUnitFields value={sales} onChange={setSales} price={price} disabled={!canEdit} />
        </CardContent>
      </Card>
      {canEdit && (
        <div className="flex justify-end">
          <Button
            disabled={loading || validateSales(sales).length > 0}
            onClick={() => void run(() => update({ variables: { id: product.id, input: { price: price.trim() || null, sales: toSalesInput(sales), ...(taxClasses ? { taxClassId: taxClassId || null } : {}) } } }), 'Venta guardada')}
          >
            Guardar venta
          </Button>
        </div>
      )}
    </div>
  )
}
