import type { Metadata } from 'next'

import { ProductCard } from '@/components/ProductCard'
import { href } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { productSummary } from '@/lib/data'
import { gql } from '@/lib/gql'
import { messages } from '@/lib/i18n'
import type { ProductSummary } from '@/lib/types'
import { requireUser } from '@/lib/user'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function WishlistPage({ params }: { params: Promise<{ locale: string }> }) {
  const locale = resolveLocale((await params).locale)
  await requireUser(locale, href.page(locale, 'wishlist'))
  const { market } = await getMarket()
  const t = messages(locale)
  const { myWishlist } = await gql<{ myWishlist: { items: ProductSummary[] } }>(`query($locale: String) { myWishlist(locale: $locale, first: 48) { items { ${productSummary(market.code)} } } }`, { locale })
  return (
    <div className="container-x mt-6">
      <h1 className="text-3xl md:text-4xl">{t.wishlist}</h1>
      {myWishlist.items.length === 0 ? <p className="mt-6">{t.wishlistEmpty}</p> : <ul className="product-grid mt-6">{myWishlist.items.map((p) => <li key={p.id}><ProductCard product={p} locale={locale} /></li>)}</ul>}
    </div>
  )
}
