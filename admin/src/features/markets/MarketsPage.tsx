import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { PageHeader } from '@/features/catalog/shared'
import { Field } from '@/features/pricing/CurrenciesPage'
import {
  AdminMarketProfilesDocument,
  SetFallbackMarketDocument,
  SetMarketCountriesDocument,
  UpdateMarketProfileDocument,
  type AdminMarketProfilesQuery,
} from '@/graphql/generated'

import { countryName, parseCountries, readiness, taxIdHint } from './countries'

type Profile = AdminMarketProfilesQuery['adminMarketProfiles'][number]

export function MarketsPage() {
  const { data, loading } = useQuery(AdminMarketProfilesDocument, { fetchPolicy: 'cache-and-network' })
  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader
        title="Tiendas por país"
        description="Cada país tiene su propia tienda: datos fiscales de quien vende, contacto, envío, almacenes, métodos de pago e impuestos. El visitante ve la tienda de su país según su IP (IPv4 o IPv6)."
      />
      {loading && !data && <Skeleton className="h-60 w-full" />}
      {data?.adminMarketProfiles.map((p) => (
        <MarketCard key={p.code} profile={p} />
      ))}
    </div>
  )
}

function MarketCard({ profile: p }: { profile: Profile }) {
  const { can } = useAuth()
  const run = useRun()
  const canManage = can('markets.manage')
  const [update, { loading }] = useMutation(UpdateMarketProfileDocument)
  const [setCountries] = useMutation(SetMarketCountriesDocument)
  const [setFallback] = useMutation(SetFallbackMarketDocument, { refetchQueries: [AdminMarketProfilesDocument] })
  const [form, setForm] = useState({
    legalName: p.legalName ?? '',
    tradeName: p.tradeName ?? '',
    taxId: p.taxId ?? '',
    taxRegime: p.taxRegime ?? '',
    invoiceSeries: p.invoiceSeries ?? '',
    contactEmail: p.contactEmail ?? '',
    contactPhone: p.contactPhone ?? '',
    supportHours: p.supportHours ?? '',
    opsEmail: p.opsEmail ?? '',
    shippingFlat: p.shippingFlat ?? '',
    shippingFreeOver: p.shippingFreeOver ?? '',
    street: p.fiscalAddress.street ?? '',
    exteriorNo: p.fiscalAddress.exteriorNo ?? '',
    neighborhood: p.fiscalAddress.neighborhood ?? '',
    city: p.fiscalAddress.city ?? '',
    state: p.fiscalAddress.state ?? '',
    zip: p.fiscalAddress.zip ?? '',
  })
  const [countries, setCountriesText] = useState(p.countries.join(', '))
  const missing = readiness(p)
  const parsed = parseCountries(countries)
  const set = (k: keyof typeof form) => (e: React.ChangeEvent<HTMLInputElement>) => setForm({ ...form, [k]: e.target.value })
  const id = (k: string) => `${p.code}-${k}`

  const save = (e: React.FormEvent) => {
    e.preventDefault()
    const blank = (v: string) => (v.trim() === '' ? null : v.trim())
    void run(
      () =>
        update({
          variables: {
            code: p.code,
            input: {
              legalName: blank(form.legalName), tradeName: blank(form.tradeName), taxId: blank(form.taxId), taxRegime: blank(form.taxRegime), invoiceSeries: blank(form.invoiceSeries),
              contactEmail: blank(form.contactEmail), contactPhone: blank(form.contactPhone), supportHours: blank(form.supportHours), opsEmail: blank(form.opsEmail),
              shippingFlat: blank(form.shippingFlat), shippingFreeOver: blank(form.shippingFreeOver),
              fiscalAddress: { street: blank(form.street), exteriorNo: blank(form.exteriorNo), neighborhood: blank(form.neighborhood), city: blank(form.city), state: blank(form.state), zip: blank(form.zip), countryCode: p.countryCode },
            },
          },
        }),
      `Datos de ${p.name} guardados.`,
    )
  }

  return (
    <section className="space-y-4 rounded-xl border bg-card p-4" aria-label={`Tienda ${p.name}`}>
      <header className="flex flex-wrap items-center gap-2">
        <h2 className="text-lg font-semibold">
          {p.name} <span className="text-sm font-normal text-muted-foreground">({p.code} · {p.defaultLocale} · {p.pricesIncludeTax ? 'precios con impuesto incluido' : 'impuesto aparte'})</span>
        </h2>
        {p.isFallback && <Badge>Tienda predeterminada</Badge>}
        {!p.isActive && <Badge variant="destructive">Inactiva</Badge>}
        <span className="flex-1" />
        {canManage && !p.isFallback && p.isActive && (
          <Button size="sm" variant="outline" onClick={() => void run(() => setFallback({ variables: { code: p.code } }), `${p.name} ahora es la tienda predeterminada.`)}>
            Hacer predeterminada
          </Button>
        )}
      </header>

      {missing.length > 0 && (
        <p role="status" className="rounded-md bg-amber-100 p-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
          Esta tienda aún no puede vender bien: falta {missing.join(', ')}.
        </p>
      )}

      <div className="grid gap-2 text-xs sm:grid-cols-3">
        <div className="rounded-md bg-muted/50 p-2">
          <strong>Almacenes</strong>
          <div>{p.warehouses.length === 0 ? 'Ninguno asignado' : p.warehouses.map((w) => `${w.code}${w.isActive ? '' : ' (inactivo)'}`).join(', ')}</div>
        </div>
        <div className="rounded-md bg-muted/50 p-2">
          <strong>Métodos de pago activos</strong>
          <div>{p.paymentProviders.length === 0 ? 'Ninguno' : p.paymentProviders.join(', ')}</div>
        </div>
        <div className="rounded-md bg-muted/50 p-2">
          <strong>Tasas de impuesto</strong>
          <div>{p.taxRateCount}</div>
        </div>
      </div>

      <form onSubmit={save} className="space-y-4" aria-label={`Datos de ${p.name}`}>
        <fieldset className="space-y-2" disabled={!canManage}>
          <legend className="font-medium">Datos fiscales de quien vende</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field id={id('legal')} label="Razón social / nombre legal">
              <Input id={id('legal')} value={form.legalName} onChange={set('legalName')} />
            </Field>
            <Field id={id('trade')} label="Nombre comercial">
              <Input id={id('trade')} value={form.tradeName} onChange={set('tradeName')} />
            </Field>
            <Field id={id('taxid')} label={p.taxIdLabel}>
              <Input id={id('taxid')} value={form.taxId} onChange={set('taxId')} aria-describedby={id('taxid-hint')} />
              <span id={id('taxid-hint')} className="text-xs text-muted-foreground">{taxIdHint(p.countryCode)}</span>
            </Field>
            <Field id={id('regime')} label="Régimen fiscal">
              <Input id={id('regime')} value={form.taxRegime} onChange={set('taxRegime')} />
            </Field>
            <Field id={id('series')} label="Serie de facturas">
              <Input id={id('series')} value={form.invoiceSeries} onChange={set('invoiceSeries')} />
            </Field>
          </div>
          <div className="grid gap-2 sm:grid-cols-3">
            <Field id={id('street')} label="Calle">
              <Input id={id('street')} value={form.street} onChange={set('street')} />
            </Field>
            <Field id={id('ext')} label="Número">
              <Input id={id('ext')} value={form.exteriorNo} onChange={set('exteriorNo')} />
            </Field>
            <Field id={id('hood')} label="Colonia / barrio">
              <Input id={id('hood')} value={form.neighborhood} onChange={set('neighborhood')} />
            </Field>
            <Field id={id('city')} label="Ciudad">
              <Input id={id('city')} value={form.city} onChange={set('city')} />
            </Field>
            <Field id={id('state')} label="Estado">
              <Input id={id('state')} value={form.state} onChange={set('state')} />
            </Field>
            <Field id={id('zip')} label="Código postal">
              <Input id={id('zip')} value={form.zip} onChange={set('zip')} />
            </Field>
          </div>
        </fieldset>

        <fieldset className="space-y-2" disabled={!canManage}>
          <legend className="font-medium">Contacto y alertas</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field id={id('email')} label="Correo de contacto (responder a)">
              <Input id={id('email')} type="email" value={form.contactEmail} onChange={set('contactEmail')} />
            </Field>
            <Field id={id('phone')} label="Teléfono">
              <Input id={id('phone')} value={form.contactPhone} onChange={set('contactPhone')} />
            </Field>
            <Field id={id('hours')} label="Horario de atención">
              <Input id={id('hours')} value={form.supportHours} onChange={set('supportHours')} />
            </Field>
            <Field id={id('ops')} label="Correo para alertas de esta tienda">
              <Input id={id('ops')} type="email" value={form.opsEmail} onChange={set('opsEmail')} />
            </Field>
          </div>
        </fieldset>

        <fieldset className="space-y-2" disabled={!canManage}>
          <legend className="font-medium">Envío (moneda de la tienda)</legend>
          <div className="grid gap-2 sm:grid-cols-2">
            <Field id={id('flat')} label="Tarifa fija (vacío = gratis)">
              <Input id={id('flat')} inputMode="decimal" value={form.shippingFlat} onChange={set('shippingFlat')} />
            </Field>
            <Field id={id('free')} label="Envío gratis desde">
              <Input id={id('free')} inputMode="decimal" value={form.shippingFreeOver} onChange={set('shippingFreeOver')} />
            </Field>
          </div>
        </fieldset>

        {canManage && (
          <Button type="submit" disabled={loading}>
            Guardar datos de {p.name}
          </Button>
        )}
      </form>

      <section className="space-y-2 border-t pt-3" aria-label={`Países de ${p.name}`}>
        <h3 className="font-medium">Países que llegan a esta tienda</h3>
        <p className="text-xs text-muted-foreground">
          Se decide por la IP del visitante. Un país pertenece a una sola tienda y el país propio ({countryName(p.countryCode)}) no se puede quitar. Quien visita desde un país sin tienda ve la predeterminada.
        </p>
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-60 flex-1">
            <Field id={id('countries')} label="Códigos de país (MX, US, PR…)">
              <Input id={id('countries')} value={countries} disabled={!canManage} onChange={(e) => setCountriesText(e.target.value)} />
            </Field>
          </div>
          {canManage && (
            <Button
              variant="outline"
              disabled={parsed.invalid.length > 0 || parsed.countries.length === 0}
              onClick={() => void run(() => setCountries({ variables: { code: p.code, countries: parsed.countries } }), 'Países actualizados.')}
            >
              Guardar países
            </Button>
          )}
        </div>
        {parsed.invalid.length > 0 && <p className="text-xs text-destructive">No son códigos de país de dos letras: {parsed.invalid.join(', ')}</p>}
      </section>
    </section>
  )
}
