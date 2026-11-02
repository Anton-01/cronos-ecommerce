import type { Metadata } from 'next'
import Link from 'next/link'

import { AddressManager } from '@/components/AddressManager'
import { getAddresses } from '@/lib/addresses'
import { href } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { messages } from '@/lib/i18n'
import { requireUser } from '@/lib/user'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function AddressesPage({ params }: { params: Promise<{ locale: string }> }) {
  const locale = resolveLocale((await params).locale)
  await requireUser(locale, href.page(locale, 'account/addresses'))
  const [addresses, { markets }] = await Promise.all([getAddresses(), getMarket()])
  const countries = [...new Set(markets.flatMap((m) => (m.profile.countries.length ? m.profile.countries : [m.countryCode])))]
  const t = messages(locale)
  return (
    <div className="container-x mt-6 space-y-6">
      <header>
        <p className="text-sm"><Link href={href.page(locale, 'account')} className="underline">{t.account}</Link></p>
        <h1 className="text-3xl md:text-4xl">{t.addresses}</h1>
      </header>
      <AddressManager addresses={addresses} locale={locale} countries={countries} />
    </div>
  )
}
