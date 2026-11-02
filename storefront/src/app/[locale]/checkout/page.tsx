import type { Metadata } from 'next'
import { redirect } from 'next/navigation'

import { CheckoutFlow } from '@/components/CheckoutFlow'
import { getAddresses } from '@/lib/addresses'
import { getCart } from '@/lib/cart'
import { href } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { messages } from '@/lib/i18n'
import { getMe } from '@/lib/user'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function CheckoutPage({ params }: { params: Promise<{ locale: string }> }) {
  const locale = resolveLocale((await params).locale)
  const cart = await getCart()
  if (!cart || cart.lines.length === 0) redirect(href.page(locale, 'cart'))
  const [{ market }, me] = await Promise.all([getMarket(), getMe()])
  const saved = me ? await getAddresses() : null // guests have no address book
  const t = messages(locale)
  const steps = [t.stepCart, t.stepCheckout, t.stepDone]
  return (
    <>
      <div className="container-x mt-6">
        <ol className="flex flex-wrap gap-4 text-lg" style={{ fontFamily: 'var(--font-heading)' }}>
          {steps.map((s, i) => <li key={s} aria-current={i === 1 ? 'step' : undefined} className={i === 1 ? 'border-b-2 font-semibold' : 'opacity-50'} style={{ borderColor: 'var(--c-primary)', color: 'var(--c-heading)' }}>{i + 1}. {s}</li>)}
        </ol>
        <h1 className="sr-only">{t.checkout}</h1>
      </div>
      <CheckoutFlow cart={cart} locale={locale} countries={market.profile.countries.length > 0 ? market.profile.countries : [market.countryCode]} defaultEmail={me?.email ?? ''} saved={saved} />
    </>
  )
}
