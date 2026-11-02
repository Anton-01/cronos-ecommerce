import type { Metadata } from 'next'

import { ListingView, parseQuery } from '@/components/Listing'
import { href } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { fill, messages } from '@/lib/i18n'
import { getFacets } from '@/lib/data'
import { getPage } from '@/lib/paging'

type Props = { params: Promise<{ locale: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> }

// Search results are personal navigation, never something to index (the API's robots.txt also disallows /search).
export const metadata: Metadata = { robots: { index: false, follow: true } }

export default async function SearchPage({ params, searchParams }: Props) {
  const locale = resolveLocale((await params).locale)
  const { market } = await getMarket()
  const query = parseQuery(await searchParams)
  const t = messages(locale)
  const args = { locale, market: market.code, search: query.q, priceMin: query.priceMin, priceMax: query.priceMax, attributes: query.attrs }
  const [data, facets] = query.q
    ? await Promise.all([getPage({ ...args, sort: query.sort === 'NEWEST' ? 'RELEVANCE' : query.sort }, query.page), getFacets(args).catch(() => null)])
    : [{ items: [], total: 0, hasNextPage: false, endCursor: null }, null]
  return (
    <>
      <div className="container-x mt-6"><h1 className="text-3xl md:text-4xl">{query.q ? fill(t.searchResults, { q: query.q }) : t.search}</h1></div>
      <ListingView data={data} facets={facets} locale={locale} base={href.page(locale, 'search')} query={{ ...query, sort: query.sort === 'NEWEST' && query.q ? 'RELEVANCE' : query.sort }} withSidebar />
    </>
  )
}
