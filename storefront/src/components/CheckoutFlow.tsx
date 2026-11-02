'use client'

import { useEffect, useRef, useState, useTransition } from 'react'

import { saveAddress } from '@/app/actions/addresses'
import { payCheckout, startCheckout, type Address, type ClientAction, type PaymentOption } from '@/app/actions/checkout'
import { LOCALES, type Locale } from '@/lib/config'
import { money } from '@/lib/format'
import { messages } from '@/lib/i18n'
import type { SavedAddress } from '@/lib/addresses'
import type { Cart } from '@/lib/types'

import { StripeForm } from './StripeForm'

const emptyAddress: Address = { recipientName: '', phone: '', street: '', exteriorNo: '', interiorNo: '', neighborhood: '', city: '', state: '', countryCode: '', zip: '', references: '' }

/** Details → payment method → provider. Steps are one page so the shopper never loses what they typed; the server re-checks everything. */
export function CheckoutFlow({ cart, locale, countries, defaultEmail, saved }: { cart: Cart; locale: Locale; countries: string[]; defaultEmail: string; saved: SavedAddress[] | null }) {
  const t = messages(locale)
  const [email, setEmail] = useState(defaultEmail)
  const toAddress = (a: SavedAddress): Address => ({ recipientName: a.recipientName, phone: a.phone ?? '', street: a.street, exteriorNo: a.exteriorNo ?? '', interiorNo: a.interiorNo ?? '', neighborhood: a.neighborhood ?? '', city: a.city, state: a.state, countryCode: a.countryCode, zip: a.zip, references: a.references ?? '' })
  const options = (saved ?? []).filter((a) => a.type === 'SHIPPING' && countries.includes(a.countryCode))
  const initial = options.find((a) => a.isDefault) ?? options[0] ?? null
  const [pickedId, setPickedId] = useState<string>(initial?.id ?? 'new')
  const [address, setAddress] = useState<Address>(initial ? toAddress(initial) : { ...emptyAddress, countryCode: countries[0] ?? '' })
  const [remember, setRemember] = useState(false)
  const key = useRef<string | null>(null)
  const [checkout, setCheckout] = useState<{ id: string; options: PaymentOption[] } | null>(null)
  const [action, setAction] = useState<{ client: ClientAction; publicKey: string | null; returnUrl: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const [pending, start] = useTransition()
  useEffect(() => { key.current ??= crypto.randomUUID() }, [])

  const set = (k: keyof Address) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setAddress({ ...address, [k]: e.target.value })
  const clean = (a: Address): Address => Object.fromEntries(Object.entries(a).filter(([, v]) => v !== '')) as unknown as Address

  const submitDetails = (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    start(async () => {
      const r = await startCheckout({ email, shippingAddress: clean(address), idempotencyKey: key.current ?? crypto.randomUUID() })
      if (r.ok && r.data) {
        setCheckout(r.data)
        // A new address the shopper asked to keep: saved now (the order is already frozen, so a failure here must never block the payment).
        if (remember && pickedId === 'new' && saved !== null) void saveAddress({ ...clean(address), type: 'SHIPPING' }).catch(() => undefined)
      } else {
        setFields(r.fields ?? {})
        setError(r.code === 'INSUFFICIENT_STOCK' ? (locale === 'es-MX' ? 'Ya no hay existencias suficientes de algún artículo. Revisa tu carrito.' : 'Some items are no longer in stock. Please review your cart.') : r.message === 'unexpected' ? t.error : (r.message ?? t.error))
        key.current = crypto.randomUUID() // a refused attempt must not be replayed with the same key
      }
    })
  }

  const pay = (provider: string) => {
    if (!checkout) return
    setError(null)
    start(async () => {
      const r = await payCheckout(checkout.id, provider, LOCALES[locale].prefix)
      if (!r.ok || !r.data) return setError(t.paymentFailed)
      const a = r.data.clientAction
      if (a?.type === 'redirect' && a.url) window.location.assign(a.url)
      else if (a?.type === 'client_secret' && a.client_secret) setAction({ client: a, publicKey: a.publishable_key ?? r.data.publicConfig?.publishable_key ?? null, returnUrl: r.data.returnUrl })
      else setError(t.paymentFailed)
    })
  }

  const totals = cart.totals
  const err = (f: string) => fields[`input.shippingAddress.${f}`]?.[0]

  return (
    <div className="container-x mt-6 grid gap-6 lg:grid-cols-[1fr_380px]" aria-busy={pending}>
      <div className="space-y-6">
        <form onSubmit={submitDetails} className="card space-y-4 p-5" aria-labelledby="ship-h">
          <h2 id="ship-h" className="text-xl">{t.shippingAddress}</h2>
          {options.length > 0 && (
            <label className="block text-sm">{t.useSaved}
              <select className="field mt-1" disabled={checkout !== null} value={pickedId} onChange={(e) => { setPickedId(e.target.value); const a = options.find((o) => o.id === e.target.value); setAddress(a ? toAddress(a) : { ...emptyAddress, countryCode: countries[0] ?? '' }) }}>
                {options.map((a) => <option key={a.id} value={a.id}>{a.label || a.recipientName} — {a.street}, {a.city}</option>)}
                <option value="new">{t.newAddress}</option>
              </select>
            </label>
          )}
          <fieldset disabled={checkout !== null || pending} className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm sm:col-span-2">{t.email}<input type="email" required autoComplete="email" className="field mt-1" value={email} onChange={(e) => setEmail(e.target.value)} /></label>
            <label className="text-sm sm:col-span-2">{t.recipient}<input required autoComplete="name" className="field mt-1" value={address.recipientName} onChange={set('recipientName')} /></label>
            <label className="text-sm sm:col-span-2">{t.street}<input required autoComplete="address-line1" className="field mt-1" value={address.street} onChange={set('street')} /></label>
            <label className="text-sm">{t.exteriorNo}<input className="field mt-1" value={address.exteriorNo} onChange={set('exteriorNo')} /></label>
            <label className="text-sm">{t.interiorNo}<input className="field mt-1" value={address.interiorNo} onChange={set('interiorNo')} /></label>
            <label className="text-sm">{t.neighborhood}<input autoComplete="address-line2" className="field mt-1" value={address.neighborhood} onChange={set('neighborhood')} /></label>
            <label className="text-sm">{t.city}<input required autoComplete="address-level2" className="field mt-1" value={address.city} onChange={set('city')} /></label>
            <label className="text-sm">{t.state}<input required autoComplete="address-level1" className="field mt-1" value={address.state} onChange={set('state')} /></label>
            <label className="text-sm">{t.zip}<input required autoComplete="postal-code" className="field mt-1" value={address.zip} onChange={set('zip')} aria-invalid={Boolean(err('zip'))} /></label>
            <label className="text-sm">{t.country}
              <select required autoComplete="country" className="field mt-1" value={address.countryCode} onChange={set('countryCode')}>{countries.map((c) => <option key={c} value={c}>{c}</option>)}</select>
            </label>
            <label className="text-sm">{t.phone}<input type="tel" autoComplete="tel" className="field mt-1" value={address.phone} onChange={set('phone')} /></label>
          </fieldset>
          {saved !== null && pickedId === 'new' && !checkout && (
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} /> {t.saveForLater}</label>
          )}
          {!checkout && <button className="btn btn-primary" disabled={pending}>{t.continueToPayment}</button>}
        </form>

        {checkout && (
          <section className="card space-y-3 p-5" aria-labelledby="pay-h">
            <h2 id="pay-h" className="text-xl">{t.payWith}</h2>
            {checkout.options.some((o) => o.mode === 'SANDBOX') && <p role="note" className="rounded-md bg-primary-soft p-2 text-sm">{t.testMode}</p>}
            {action ? (
              <StripeForm clientSecret={action.client.client_secret!} publishableKey={action.publicKey} returnUrl={action.returnUrl} locale={locale} />
            ) : checkout.options.length === 0 ? (
              <p role="alert" className="text-sm">{t.noPaymentMethods}</p>
            ) : (
              <div className="flex flex-wrap gap-3">
                {checkout.options.map((o) => <button key={o.provider} type="button" className="btn btn-dark" disabled={pending} onClick={() => pay(o.provider)}>{o.name}</button>)}
              </div>
            )}
          </section>
        )}
        {error && <p role="alert" className="text-sm text-danger">{error}</p>}
      </div>

      <aside className="card h-fit space-y-3 p-5" aria-label={t.summary}>
        <h2 className="text-xl">{t.summary}</h2>
        <ul className="divide-y text-sm" style={{ borderColor: 'var(--c-border)' }}>
          {cart.lines.map((l) => <li key={l.productId} className="flex justify-between gap-3 py-2"><span>{l.name} × {Number(l.quantity)}{l.byRoll ? ` (${t.roll})` : ''}</span><span>{money(l.total, totals?.currency ?? 'USD', locale)}</span></li>)}
        </ul>
        {totals && (
          <dl className="space-y-1 border-t pt-3 text-sm" style={{ borderColor: 'var(--c-border)' }}>
            <div className="flex justify-between"><dt>{t.subtotal}</dt><dd>{money(totals.subtotal, totals.currency, locale)}</dd></div>
            {Number(totals.itemsDiscount) > 0 && <div className="flex justify-between"><dt>{t.discount}</dt><dd>− {money(totals.itemsDiscount, totals.currency, locale)}</dd></div>}
            <div className="flex justify-between"><dt>{t.shipping}</dt><dd>{Number(totals.shipping) > 0 ? money(totals.shipping, totals.currency, locale) : t.shippingAtCheckout}</dd></div>
            <div className="flex justify-between"><dt>{totals.taxIncluded ? t.taxIncluded : t.tax}</dt><dd>{money(totals.tax, totals.currency, locale)}</dd></div>
            <div className="flex justify-between text-base font-semibold" style={{ color: 'var(--c-heading)' }}><dt>{t.total}</dt><dd>{money(totals.total, totals.currency, locale)}</dd></div>
          </dl>
        )}
      </aside>
    </div>
  )
}
