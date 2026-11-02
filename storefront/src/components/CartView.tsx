'use client'

import Link from 'next/link'
import { useState, useTransition } from 'react'

import { applyCoupon, removeLine, setLine } from '@/app/actions/cart'
import { href, type Locale } from '@/lib/config'
import { money, quantity } from '@/lib/format'
import { messages } from '@/lib/i18n'
import type { Cart } from '@/lib/types'

/** Editable cart. Every change goes to the API, which re-prices everything (promotions, tax, stock): what is shown is always what it says. */
export function CartView({ cart: initial, locale, links }: { cart: Cart; locale: Locale; links: Record<string, string> }) {
  const t = messages(locale)
  const [cart, setCart] = useState(initial)
  const [error, setError] = useState<string | null>(null)
  const [coupon, setCoupon] = useState('')
  const [pending, start] = useTransition()

  const apply = (run: () => Promise<{ ok: boolean; message?: string; cart?: Cart }>) =>
    start(async () => {
      const r = await run()
      if (r.ok && r.cart) {
        setCart(r.cart)
        setError(null)
      } else if (!r.ok) setError(r.message ?? t.error)
    })

  const totals = cart.totals
  const cur = totals?.currency ?? 'USD'
  return (
    <div className="container-x mt-6 grid gap-6 lg:grid-cols-[1fr_380px]" aria-busy={pending}>
      <section aria-label={t.cart}>
        <ul className="space-y-3">
          {cart.lines.map((l) => (
            <li key={l.productId} className="card flex flex-wrap items-center justify-between gap-3 p-4">
              <div className="min-w-0 flex-1">
                <p className="font-semibold" style={{ color: 'var(--c-heading)' }}>{l.name}</p>
                <p className="text-xs">{l.sku} · {l.byRoll ? t.roll : ''} {money(l.unitPrice, cur, locale)}</p>
              </div>
              <form className="flex items-center gap-2" action={() => {}} onSubmit={(e) => { e.preventDefault(); const q = new FormData(e.currentTarget).get('qty'); apply(() => setLine(l.productId, String(q).replace(',', '.'), l.byRoll)) }}>
                <label className="sr-only" htmlFor={`q-${l.productId}`}>{t.quantity}</label>
                <input id={`q-${l.productId}`} name="qty" inputMode="decimal" defaultValue={quantity(l.quantity, 'en-US')} className="field w-24 text-center" />
                <button className="btn btn-ghost" type="submit" disabled={pending}>{t.update}</button>
              </form>
              <p className="w-28 text-right font-semibold" style={{ color: 'var(--c-heading)' }}>{money(l.total, cur, locale)}</p>
              <button type="button" className="text-sm underline" disabled={pending} onClick={() => apply(() => removeLine(l.productId))}>{t.remove}</button>
            </li>
          ))}
        </ul>
        {(error || cart.warning) && <p role="alert" className="mt-3 text-sm text-danger">{error ?? cart.warning}</p>}
      </section>

      <aside className="card h-fit space-y-4 p-5" aria-label={t.total}>
        <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); apply(() => applyCoupon(coupon)) }}>
          <label className="sr-only" htmlFor="coupon">{t.coupon}</label>
          <input id="coupon" className="field" placeholder={t.coupon} value={coupon} onChange={(e) => setCoupon(e.target.value)} />
          <button className="btn btn-dark" type="submit" disabled={pending || coupon.trim() === ''}>{t.applyCoupon}</button>
        </form>
        {cart.couponCode && !cart.couponError && <p className="text-sm">{cart.couponCode} <button className="underline" type="button" onClick={() => apply(() => applyCoupon(null))}>{t.removeCoupon}</button></p>}
        {cart.couponError && <p role="alert" className="text-sm text-danger">{cart.couponError}</p>}
        {totals && (
          <dl className="space-y-2 text-sm">
            <Row label={t.subtotal} value={money(totals.subtotal, cur, locale)} />
            {Number(totals.itemsDiscount) > 0 && <Row label={t.discount} value={`− ${money(totals.itemsDiscount, cur, locale)}`} />}
            <Row label={t.shipping} value={Number(totals.shipping) > 0 ? money(totals.shipping, cur, locale) : t.shippingAtCheckout} />
            {Number(totals.tax) > 0 && <Row label={totals.taxIncluded ? t.taxIncluded : t.tax} value={money(totals.tax, cur, locale)} />}
            <div className="flex justify-between border-t pt-3 text-base font-semibold" style={{ borderColor: 'var(--c-border)', color: 'var(--c-heading)' }}><dt>{t.total}</dt><dd>{money(totals.total, cur, locale)}</dd></div>
          </dl>
        )}
        <Link href={links.checkout!} className="btn btn-primary w-full" aria-disabled={!totals}>{t.checkout}</Link>
      </aside>
    </div>
  )
}

function Row({ label, value }: { label: string; value: string }) {
  return <div className="flex justify-between"><dt>{label}</dt><dd>{value}</dd></div>
}

export { href }
