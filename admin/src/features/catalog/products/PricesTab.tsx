import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { fromApiDate, toApiDate } from '@/features/promotions/dates'
import {
  AdminCurrenciesDocument,
  AdminProductPricesDocument,
  ClearProductPriceDocument,
  SetProductPriceDocument,
  type AdminCurrenciesQuery,
  type AdminProductPricesQuery,
} from '@/graphql/generated'

import { Labeled } from './GeneralTab'
import { type Product } from './tabs'
import { useRun } from './useRun'

type Currency = AdminCurrenciesQuery['adminCurrencies'][number]
type Row = AdminProductPricesQuery['adminProductPrices'][number]

/** Estimated price in another currency: the base price times the exchange rate, shown while no price of its own is set. */
export const estimate = (base: string | null | undefined, currency: Currency): string => {
  if (!base || currency.isDefault) return base ?? ''
  return (Number(base) * Number(currency.exchangeRate)).toFixed(currency.decimals)
}

export function PricesTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const { data: currencies } = useQuery(AdminCurrenciesDocument)
  const { data: rows } = useQuery(AdminProductPricesDocument, { variables: { productId: product.id } })
  const sellsByRoll = product.sales.sellByRoll

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        El precio base ({product.price ?? 'sin precio'}) está en la moneda base y se edita en la pestaña Venta. Aquí puedes fijar un precio propio por moneda, un precio tachado y una oferta programada. Sin precio propio, la
        moneda se convierte con su tipo de cambio.
      </p>
      {currencies?.adminCurrencies
        .filter((c) => c.isActive)
        .map((c) => (
          <CurrencyCard key={c.id + (rows?.adminProductPrices.find((r) => r.currencyId === c.id)?.price ?? '')} product={product} currency={c} row={rows?.adminProductPrices.find((r) => r.currencyId === c.id) ?? null} canEdit={canEdit} sellsByRoll={sellsByRoll} />
        ))}
    </div>
  )
}

function CurrencyCard({ product, currency, row, canEdit, sellsByRoll }: { product: Product; currency: Currency; row: Row | null; canEdit: boolean; sellsByRoll: boolean }) {
  const run = useRun()
  const [save, { loading }] = useMutation(SetProductPriceDocument, { refetchQueries: ['AdminProductPrices'] })
  const [clear] = useMutation(ClearProductPriceDocument, { refetchQueries: ['AdminProductPrices'] })
  const [form, setForm] = useState({
    price: row?.price ?? '',
    rollPrice: row?.rollPrice ?? '',
    compareAtPrice: row?.compareAtPrice ?? '',
    salePrice: row?.salePrice ?? '',
    saleStartsAt: fromApiDate(row?.saleStartsAt),
    saleEndsAt: fromApiDate(row?.saleEndsAt),
  })
  const set = (key: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [key]: e.target.value })
  const id = (key: string) => `${currency.code}-${key}`
  const blank = (v: string) => v.trim() || null

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-base">
          {currency.code} {currency.isDefault && <Badge>Moneda base</Badge>} {row?.price && <Badge variant="secondary">Precio propio</Badge>}
        </CardTitle>
        <CardDescription>{currency.isDefault ? 'Vacío = precio base del producto.' : `Vacío = ${estimate(product.price, currency) || '—'} ${currency.code} (convertido).`}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="grid gap-3 sm:grid-cols-3">
          <Labeled id={id('price')} label={`Precio ${currency.code}`}>
            <Input id={id('price')} inputMode="decimal" value={form.price} disabled={!canEdit} onChange={set('price')} />
          </Labeled>
          {sellsByRoll && (
            <Labeled id={id('roll')} label={`Precio del rollo ${currency.code}`}>
              <Input id={id('roll')} inputMode="decimal" value={form.rollPrice} disabled={!canEdit} onChange={set('rollPrice')} />
            </Labeled>
          )}
          <Labeled id={id('compare')} label="Precio tachado">
            <Input id={id('compare')} inputMode="decimal" value={form.compareAtPrice} disabled={!canEdit} onChange={set('compareAtPrice')} />
          </Labeled>
        </div>
        <div className="grid gap-3 sm:grid-cols-3">
          <Labeled id={id('sale')} label="Precio de oferta">
            <Input id={id('sale')} inputMode="decimal" value={form.salePrice} disabled={!canEdit} onChange={set('salePrice')} />
          </Labeled>
          <Labeled id={id('from')} label="Oferta desde (UTC)">
            <Input id={id('from')} type="datetime-local" value={form.saleStartsAt} disabled={!canEdit} onChange={set('saleStartsAt')} />
          </Labeled>
          <Labeled id={id('to')} label="Oferta hasta (UTC)">
            <Input id={id('to')} type="datetime-local" value={form.saleEndsAt} disabled={!canEdit} onChange={set('saleEndsAt')} />
          </Labeled>
        </div>
        {canEdit && (
          <div className="flex justify-end gap-2">
            {row && (
              <Button variant="ghost" onClick={() => void run(() => clear({ variables: { productId: product.id, currencyId: currency.id } }), `Precios de ${currency.code} restablecidos`)}>
                Restablecer
              </Button>
            )}
            <Button
              disabled={loading}
              onClick={() =>
                void run(
                  () =>
                    save({
                      variables: {
                        productId: product.id,
                        currencyId: currency.id,
                        input: { price: blank(form.price), rollPrice: blank(form.rollPrice), compareAtPrice: blank(form.compareAtPrice), salePrice: blank(form.salePrice), saleStartsAt: toApiDate(form.saleStartsAt), saleEndsAt: toApiDate(form.saleEndsAt) },
                      },
                    }),
                  `Precios de ${currency.code} guardados`,
                )
              }
            >
              Guardar {currency.code}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
