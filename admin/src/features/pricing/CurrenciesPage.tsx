import { useMutation, useQuery } from '@apollo/client/react'
import { Plus, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useClock } from '@/lib/clock'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import {
  AdminCurrenciesDocument,
  AdminCurrencyHistoryDocument,
  AdminMarketsDocument,
  CreateCurrencyDocument,
  CreateMarketDocument,
  DeleteMarketDocument,
  RefreshExchangeRatesDocument,
  SetExchangeRateDocument,
  UpdateCurrencyDocument,
  UpdateMarketDocument,
  type AdminCurrenciesQuery,
  type AdminMarketsQuery,
} from '@/graphql/generated'

type Currency = AdminCurrenciesQuery['adminCurrencies'][number]
type Market = AdminMarketsQuery['adminMarkets'][number]

export function CurrenciesPage() {
  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title="Monedas y mercados" description="Cada mercado vende en una moneda y muestra los precios con o sin impuesto, según el país." />
      <Tabs defaultValue="currencies">
        <TabsList>
          <TabsTrigger value="currencies">Monedas</TabsTrigger>
          <TabsTrigger value="markets">Mercados</TabsTrigger>
        </TabsList>
        <TabsContent value="currencies">
          <Currencies />
        </TabsContent>
        <TabsContent value="markets">
          <Markets />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function Currencies() {
  const clock = useClock()
  const { can } = useAuth()
  const canManage = can('pricing.manage')
  const run = useRun()
  const { data, loading } = useQuery(AdminCurrenciesDocument)
  const [refresh, { loading: refreshing }] = useMutation(RefreshExchangeRatesDocument, { refetchQueries: ['AdminCurrencies'] })
  const [update] = useMutation(UpdateCurrencyDocument, { refetchQueries: ['AdminCurrencies'] })
  const [dialog, setDialog] = useState<{ kind: 'new' } | { kind: 'rate'; currency: Currency } | null>(null)

  const refreshRates = async () => {
    try {
      const { data: result } = await refresh()
      const report = result?.refreshExchangeRates
      if (report?.skipped.length) toast.warning(`Sin actualizar: ${report.skipped.map((s) => `${s.code} (${s.reason})`).join('; ')}`)
      else toast.success(report?.updated.length ? 'Tipos de cambio actualizados' : 'No hay monedas por actualizar')
    } catch (error) {
      await run(() => Promise.reject(error), '')
    }
  }

  return (
    <div className="space-y-3 pt-3">
      {canManage && (
        <div className="flex justify-end gap-2">
          <Button variant="outline" disabled={refreshing} onClick={() => void refreshRates()}>
            <RefreshCw /> Actualizar tipos de cambio
          </Button>
          <Button onClick={() => setDialog({ kind: 'new' })}>
            <Plus /> Nueva moneda
          </Button>
        </div>
      )}
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminCurrencies.map((c) => (
          <li key={c.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-12 font-mono font-semibold">{c.code}</span>
            <span className="flex-1">
              {c.name} <span className="text-muted-foreground">({c.symbol})</span>
            </span>
            {c.isDefault ? (
              <Badge>Moneda base</Badge>
            ) : (
              <span className="text-sm tabular-nums text-muted-foreground" title={c.rateUpdatedAt ? `Actualizado ${clock.formatDateTime(c.rateUpdatedAt)}` : undefined}>
                1 base = {Number(c.exchangeRate)} {c.code}
              </span>
            )}
            {!c.isActive && <Badge variant="outline">Inactiva</Badge>}
            {canManage && !c.isDefault && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'rate', currency: c })}>
                  Tipo de cambio
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => update({ variables: { id: c.id, input: { isActive: !c.isActive } } }), c.isActive ? 'Moneda desactivada' : 'Moneda activada')}>
                  {c.isActive ? 'Desactivar' : 'Activar'}
                </Button>
              </>
            )}
          </li>
        ))}
      </ul>
      <Dialog open={dialog !== null} onOpenChange={(o) => !o && setDialog(null)}>
        <DialogContent>
          {dialog?.kind === 'new' && <CurrencyForm onClose={() => setDialog(null)} />}
          {dialog?.kind === 'rate' && <RateForm currency={dialog.currency} onClose={() => setDialog(null)} />}
        </DialogContent>
      </Dialog>
    </div>
  )
}

function CurrencyForm({ onClose }: { onClose: () => void }) {
  const run = useRun()
  const [create] = useMutation(CreateCurrencyDocument, { refetchQueries: ['AdminCurrencies'] })
  const [form, setForm] = useState({ code: '', name: '', symbol: '', decimals: '2', exchangeRate: '' })
  const valid = /^[A-Za-z]{3}$/.test(form.code) && form.name.trim() && form.symbol.trim() && Number(form.exchangeRate) > 0

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => create({ variables: { input: { code: form.code, name: form.name.trim(), symbol: form.symbol.trim(), decimals: Number(form.decimals), exchangeRate: form.exchangeRate } } }), 'Moneda creada').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Nueva moneda</DialogTitle>
        <DialogDescription>El tipo de cambio es cuántas unidades de esta moneda equivalen a 1 de la moneda base.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="cur-code" label="Código (ISO)">
          <Input id="cur-code" maxLength={3} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="cur-symbol" label="Símbolo">
          <Input id="cur-symbol" maxLength={8} value={form.symbol} onChange={(e) => setForm({ ...form, symbol: e.target.value })} />
        </Field>
        <Field id="cur-name" label="Nombre">
          <Input id="cur-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="cur-decimals" label="Decimales">
          <NativeSelect id="cur-decimals" value={form.decimals} onChange={(e) => setForm({ ...form, decimals: e.target.value })}>
            {[0, 2, 3, 4].map((d) => (
              <option key={d}>{d}</option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="cur-rate" label="Tipo de cambio">
          <Input id="cur-rate" inputMode="decimal" value={form.exchangeRate} onChange={(e) => setForm({ ...form, exchangeRate: e.target.value })} />
        </Field>
      </div>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}

function RateForm({ currency, onClose }: { currency: Currency; onClose: () => void }) {
  const clock = useClock()
  const run = useRun()
  const [setRate] = useMutation(SetExchangeRateDocument, { refetchQueries: ['AdminCurrencies', 'AdminCurrencyHistory'] })
  const { data } = useQuery(AdminCurrencyHistoryDocument, { variables: { currencyId: currency.id } })
  const [rate, setRateValue] = useState(String(Number(currency.exchangeRate)))

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => setRate({ variables: { currencyId: currency.id, rate } }), 'Tipo de cambio guardado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Tipo de cambio {currency.code}</DialogTitle>
        <DialogDescription>Los precios en {currency.code} que no tengan valor propio se convierten con este tipo de cambio.</DialogDescription>
      </DialogHeader>
      <Field id="rate" label={`1 moneda base = ... ${currency.code}`}>
        <Input id="rate" inputMode="decimal" value={rate} onChange={(e) => setRateValue(e.target.value)} />
      </Field>
      {data && data.adminCurrencyHistory.length > 0 && (
        <div>
          <p className="mb-1 text-xs font-medium text-muted-foreground">Historial</p>
          <ul className="max-h-32 divide-y overflow-auto rounded-md border text-xs">
            {data.adminCurrencyHistory.map((h) => (
              <li key={h.createdAt + h.rate} className="flex justify-between px-2 py-1">
                <span className="tabular-nums">{Number(h.rate)}</span>
                <span className="text-muted-foreground">
                  {h.source} · {clock.formatDateTime(h.createdAt)}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
      <Actions onClose={onClose} disabled={!(Number(rate) > 0)} />
    </form>
  )
}

function Markets() {
  const { can } = useAuth()
  const canManage = can('pricing.manage')
  const run = useRun()
  const { data, loading } = useQuery(AdminMarketsDocument)
  const { data: currencies } = useQuery(AdminCurrenciesDocument)
  const [remove] = useMutation(DeleteMarketDocument, { refetchQueries: ['AdminMarkets'] })
  const [editing, setEditing] = useState<{ market: Market | null } | null>(null)
  const code = (id: string) => currencies?.adminCurrencies.find((c) => c.id === id)?.code ?? ''

  return (
    <div className="space-y-3 pt-3">
      {canManage && (
        <div className="flex justify-end">
          <Button onClick={() => setEditing({ market: null })}>
            <Plus /> Nuevo mercado
          </Button>
        </div>
      )}
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminMarkets.map((m) => (
          <li key={m.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-12 font-mono font-semibold">{m.code}</span>
            <span className="flex-1">
              {m.name} <span className="text-muted-foreground">· {m.countryCode} · {code(m.currencyId)} · {m.defaultLocale}</span>
            </span>
            <Badge variant={m.pricesIncludeTax ? 'success' : 'secondary'}>{m.pricesIncludeTax ? 'Precios con impuesto' : 'Impuesto aparte'}</Badge>
            {!m.isActive && <Badge variant="outline">Inactivo</Badge>}
            {canManage && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ market: m })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: m.id } }), 'Mercado eliminado')}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent>{editing && <MarketForm key={editing.market?.id ?? 'new'} market={editing.market} currencies={currencies?.adminCurrencies ?? []} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function MarketForm({ market, currencies, onClose }: { market: Market | null; currencies: Currency[]; onClose: () => void }) {
  const run = useRun()
  const [create] = useMutation(CreateMarketDocument, { refetchQueries: ['AdminMarkets'] })
  const [update] = useMutation(UpdateMarketDocument, { refetchQueries: ['AdminMarkets'] })
  const [form, setForm] = useState({
    code: market?.code ?? '',
    name: market?.name ?? '',
    countryCode: market?.countryCode ?? '',
    currencyId: market?.currencyId ?? currencies.find((c) => c.isDefault)?.id ?? '',
    defaultLocale: market?.defaultLocale ?? 'es-MX',
    pricesIncludeTax: market?.pricesIncludeTax ?? false,
    isActive: market?.isActive ?? true,
  })
  const valid = form.code.trim().length >= 2 && form.name.trim() && /^[A-Za-z]{2}$/.test(form.countryCode) && form.currencyId

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        const input = { ...form, name: form.name.trim() }
        void run(() => (market ? update({ variables: { id: market.id, input } }) : create({ variables: { input } })), 'Mercado guardado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>{market ? 'Editar mercado' : 'Nuevo mercado'}</DialogTitle>
        <DialogDescription>Un mercado es un país donde vendes: su moneda, su idioma y cómo se muestran los impuestos.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="mk-code" label="Código">
          <Input id="mk-code" maxLength={12} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="mk-name" label="Nombre">
          <Input id="mk-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="mk-country" label="País (ISO)">
          <Input id="mk-country" maxLength={2} value={form.countryCode} onChange={(e) => setForm({ ...form, countryCode: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="mk-currency" label="Moneda">
          <NativeSelect id="mk-currency" value={form.currencyId} onChange={(e) => setForm({ ...form, currencyId: e.target.value })}>
            {currencies.filter((c) => c.isActive || c.id === form.currencyId).map((c) => (
              <option key={c.id} value={c.id}>
                {c.code}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="mk-locale" label="Idioma">
          <NativeSelect id="mk-locale" value={form.defaultLocale} onChange={(e) => setForm({ ...form, defaultLocale: e.target.value })}>
            <option>es-MX</option>
            <option>en-US</option>
          </NativeSelect>
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.pricesIncludeTax} onCheckedChange={(v) => setForm({ ...form, pricesIncludeTax: v === true })} />
        Los precios ya incluyen impuesto (como el IVA en México)
      </label>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isActive} onCheckedChange={(v) => setForm({ ...form, isActive: v === true })} />
        Mercado activo
      </label>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}

export function Field({ id, label, children }: { id: string; label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
    </div>
  )
}

export function Actions({ onClose, disabled, label = 'Guardar' }: { onClose: () => void; disabled?: boolean; label?: string }) {
  return (
    <div className="flex justify-end gap-2">
      <Button type="button" variant="outline" onClick={onClose}>
        Cancelar
      </Button>
      <Button type="submit" disabled={disabled}>
        {label}
      </Button>
    </div>
  )
}
