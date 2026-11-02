import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'

import { isRefined, ListingView, parseQuery } from '@/components/Listing'
import { getFacets } from '@/lib/data'
import { href, LOCALES, SITE_URL, type Locale } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { gql } from '@/lib/gql'
import { messages } from '@/lib/i18n'
import { getPage } from '@/lib/paging'
import type { Image } from '@/lib/types'

interface Category { id: string; slug: string | null; name: string | null; description: string | null; breadcrumb: { slug: string | null; name: string | null }[]; children: { slug: string | null; name: string | null }[]; seo: { title: string | null; description: string | null }; image: Image | null }

async function load(locale: Locale, slug: string): Promise<Category | null> {
  const data = await gql<{ category: Category | null }>(
    `query($locale: String, $slug: String!) { category(locale: $locale, slug: $slug) { id slug name description breadcrumb { slug name } children { slug name } seo { title description } image { id url thumbUrl srcset alt width height } } }`,
    { locale, slug },
    { revalidate: 6 * 60 * 60, tags: ['categories'] },
  )
  return data.category
}

export async function categoryMetadata(rawLocale: string, slug: string, searchParams: Record<string, string | string[] | undefined> = {}): Promise<Metadata> {
  const locale = resolveLocale(rawLocale)
  const category = await load(locale, slug)
  if (!category) return {}
  return {
    title: category.seo.title ?? category.name ?? undefined,
    description: category.seo.description ?? undefined,
    alternates: { canonical: `${SITE_URL}${href.category(locale, slug)}` },
    // Filtered, sorted or later pages repeat the same products: crawlers follow them but only the clean listing is indexed.
    robots: isRefined(parseQuery(searchParams)) ? { index: false, follow: true } : undefined,
  }
}

export async function CategoryView({ rawLocale, slug, searchParams }: { rawLocale: string; slug: string; searchParams: Record<string, string | string[] | undefined> }) {
  const locale = resolveLocale(rawLocale)
  // The URL must use the segment of its own language (/categorias in Spanish only): the other one is a different page for crawlers.
  const category = await load(locale, slug)
  if (!category) notFound()
  const { market } = await getMarket()
  const query = parseQuery(searchParams)
  const args = { locale, market: market.code, category: slug, priceMin: query.priceMin, priceMax: query.priceMax, attributes: query.attrs }
  const [data, facets] = await Promise.all([getPage({ ...args, sort: query.sort }, query.page), getFacets(args).catch(() => null)])
  const t = messages(locale)
  const base = href.category(locale, slug)

  return (
    <>
      <div className="container-x mt-4">
        <nav aria-label="breadcrumb" className="text-sm">
          <ol className="flex flex-wrap items-center gap-2">
            <li><Link href={href.home(locale)} className="hover:text-primary">{t.home}</Link></li>
            {category.breadcrumb.map((b) => <li key={b.slug} className="flex gap-2"><span aria-hidden>/</span><Link href={href.category(locale, b.slug ?? '')} className="hover:text-primary">{b.name}</Link></li>)}
            <li className="flex gap-2"><span aria-hidden>/</span><span aria-current="page">{category.name}</span></li>
          </ol>
        </nav>
        <h1 className="mt-3 text-4xl md:text-5xl">{category.name}</h1>
        {category.description && <p className="mt-2 max-w-2xl">{category.description}</p>}
        {category.children.length > 0 && (
          <ul className="mt-4 flex flex-wrap gap-2">
            {category.children.map((c) => <li key={c.slug}><Link href={href.category(locale, c.slug ?? '')} className="rounded-full bg-surface px-4 py-1.5 text-sm">{c.name}</Link></li>)}
          </ul>
        )}
      </div>
      <ListingView data={data} facets={facets} locale={locale} base={base} query={query} withSidebar />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify({ '@context': 'https://schema.org', '@type': 'CollectionPage', name: category.name, url: `${SITE_URL}${base}` }).replace(/</g, '\\u003c') }} />
    </>
  )
}

export const segments = Object.fromEntries(Object.entries(LOCALES).map(([l, v]) => [l, v.category]))
