import type { Metadata } from 'next'
import Link from 'next/link'

import { CartView } from '@/components/CartView'
import { getCart } from '@/lib/cart'
import { href } from '@/lib/config'
import { resolveLocale } from '@/lib/context'
import { messages } from '@/lib/i18n'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function CartPage({ params }: { params: Promise<{ locale: string }> }) {
  const locale = resolveLocale((await params).locale)
  const t = messages(locale)
  const cart = await getCart()
  const steps = [t.stepCart, t.stepCheckout, t.stepDone]
  return (
    <>
      <div className="container-x mt-6">
        <ol className="flex flex-wrap items-center gap-4 text-lg" style={{ fontFamily: 'var(--font-heading)' }}>
          {steps.map((s, i) => <li key={s} aria-current={i === 0 ? 'step' : undefined} className={i === 0 ? 'border-b-2 font-semibold' : 'opacity-50'} style={{ borderColor: 'var(--c-primary)', color: 'var(--c-heading)' }}>{i + 1}. {s}</li>)}
        </ol>
        <h1 className="sr-only">{t.cart}</h1>
      </div>
      {cart && cart.lines.length > 0 ? (
        <CartView cart={cart} locale={locale} links={{ checkout: href.page(locale, 'checkout') }} />
      ) : (
        <div className="container-x mt-10 flex flex-col items-center gap-3 py-12 text-center">
          <p className="text-2xl" style={{ fontFamily: 'var(--font-heading)', color: 'var(--c-heading)' }}>{t.emptyCart}</p>
          <p>{t.emptyCartText}</p>
          <Link href={href.home(locale)} className="btn btn-primary mt-2">{t.returnToShop}</Link>
        </div>
      )}
    </>
  )
}
