import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'

import { href, LOCALES, SITE_URL, type Locale } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { getProducts, PRODUCTS } from '@/lib/data'
import { gql } from '@/lib/gql'
import { messages } from '@/lib/i18n'
import type { Image, Pricing, ProductSummary, Sales, Stock } from '@/lib/types'

import { BuyBox, type Attribute, type Variation } from './BuyBox'
import { Gallery } from './Gallery'
import { VariationImages } from './VariationImages'
import { Price } from './Price'
import { ProductCard } from './ProductCard'

const PRICING = (m: string) => `pricing(market: "${m}") { market currency taxIncluded price { amount currency } listPrice { amount currency } compareAt { amount currency } onSale priceWithTax { amount currency } priceWithoutTax { amount currency } taxAmount { amount currency } rollPrice { amount currency } rollPriceWithTax { amount currency } }`

interface Product {
  id: string; type: string; sku: string | null; slug: string | null; name: string | null; shortDescription: string | null; description: string | null
  sales: Sales; stockStatus: Stock; stockStatusIn: Stock; gallery: Image[]
  categories: { slug: string | null; name: string | null; isPrimary: boolean }[]
  attributes: (Attribute & { isVisible: boolean; isVariation: boolean })[]
  variations: (Variation & { gallery: Image[] })[]
  seo: { title: string | null; description: string | null; canonicalSlug: string | null; alternates: { locale: string; slug: string | null }[] }
  pricing: Pricing | null
}

async function load(locale: Locale, slug: string, market: string, token: string | null): Promise<Product | null> {
  const IMG = 'id url thumbUrl srcset alt width height'
  const query = `query($locale: String, $slug: String!${token ? ', $token: String!' : ''}) {
    ${token ? 'product: productPreview(locale: $locale, token: $token)' : 'product(locale: $locale, slug: $slug)'} {
      id type sku slug name shortDescription description
      sales { unit step min max sellByCut sellByRoll rollLength rollPrice }
      stockStatus stockStatusIn(market: "${market}")
      gallery { ${IMG} }
      categories { slug name isPrimary }
      attributes { code name isVisible isVariation values { id label meta } }
      variations { id sku stockStatus valueIds gallery { ${IMG} } ${PRICING(market)} }
      seo { title description canonicalSlug alternates { locale slug } }
      ${PRICING(market)}
    } }`
  const data = await gql<{ product: Product | null }>(query, { locale, slug, token }, token ? {} : { revalidate: PRODUCTS, tags: ['products'] })
  return data.product
}

export async function productMetadata(rawLocale: string, slug: string): Promise<Metadata> {
  const locale = resolveLocale(rawLocale)
  const { market } = await getMarket()
  const p = await load(locale, slug, market.code, null)
  if (!p) return {}
  const image = p.gallery[0]?.url
  return {
    title: p.seo.title ?? p.name ?? undefined,
    description: p.seo.description ?? p.shortDescription ?? undefined,
    alternates: {
      canonical: `${SITE_URL}${href.product(locale, p.seo.canonicalSlug ?? slug)}`,
      languages: Object.fromEntries(p.seo.alternates.filter((a) => a.slug).map((a) => [a.locale, `${SITE_URL}${href.product(a.locale as Locale, a.slug!)}`])),
    },
    openGraph: { title: p.seo.title ?? p.name ?? undefined, description: p.seo.description ?? undefined, images: image ? [image] : undefined, type: 'website' },
  }
}

export async function ProductView({ rawLocale, slug }: { rawLocale: string; slug: string }) {
  const locale = resolveLocale(rawLocale)
  const { market } = await getMarket()
  const p = await load(locale, slug, market.code, null)
  if (!p) notFound()
  const t = messages(locale)
  const primary = p.categories.find((c) => c.isPrimary) ?? p.categories[0]
  const related = primary?.slug ? (await getProducts({ locale, market: market.code, category: primary.slug, first: 5 }).catch(() => ({ items: [] as ProductSummary[] }))).items.filter((x) => x.id !== p.id).slice(0, 4) : []
  const details = p.attributes.filter((a) => a.isVisible && !a.isVariation && a.values.length > 0)
  const inStock = p.stockStatusIn !== 'OUT_OF_STOCK'
  const images = p.gallery.length > 0 ? p.gallery : p.variations.flatMap((v) => v.gallery).slice(0, 1)

  const jsonLd = {
    '@context': 'https://schema.org', '@type': 'Product', name: p.name, sku: p.sku ?? undefined, description: p.shortDescription ?? undefined,
    image: images.map((i) => i.url),
    offers: p.pricing ? { '@type': 'Offer', priceCurrency: p.pricing.currency, price: p.pricing.price.amount, availability: inStock ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock', url: `${SITE_URL}${href.product(locale, slug)}` } : undefined,
  }

  return (
    <div className="container-x mt-4">
      <nav aria-label="breadcrumb" className="text-sm">
        <ol className="flex flex-wrap items-center gap-2">
          <li><Link href={href.home(locale)} className="hover:text-primary">{t.home}</Link></li>
          {primary?.slug && <li className="flex gap-2"><span aria-hidden>/</span><Link href={href.category(locale, primary.slug)} className="hover:text-primary">{primary.name}</Link></li>}
          <li className="flex gap-2"><span aria-hidden>/</span><span aria-current="page">{p.name}</span></li>
        </ol>
      </nav>

      <VariationImages productImages={images} byVariation={Object.fromEntries(p.variations.filter((v) => v.gallery.length > 0).map((v) => [v.id, v.gallery]))}>
      <div className="mt-4 grid gap-8 lg:grid-cols-2">
        <Gallery images={images} name={p.name ?? ''} />
        <div className="space-y-4">
          <h1 className="text-3xl md:text-4xl">{p.name}</h1>
          {p.sku && <p className="text-sm">{t.sku}: {p.sku}</p>}
          <p className="text-sm font-medium" style={{ color: inStock ? 'var(--c-success)' : 'var(--c-danger)' }}>{p.stockStatusIn === 'IN_STOCK' ? t.inStock : p.stockStatusIn === 'ON_BACKORDER' ? t.backorder : t.outOfStock}</p>
          {p.type !== 'VARIABLE' && <Price pricing={p.pricing} sales={p.sales} locale={locale} size="lg" />}
          {p.type === 'VARIABLE' && p.pricing && <Price pricing={p.pricing} sales={p.sales} locale={locale} size="lg" />}
          {p.shortDescription && <p>{p.shortDescription}</p>}
          <BuyBox locale={locale} market={market.code} productId={p.id} sales={p.sales} pricing={p.pricing} stock={p.stockStatusIn} attributes={p.attributes} variations={p.variations} />
        </div>
      </div>
      </VariationImages>

      {(p.description || details.length > 0) && (
        <div className="card mt-10 grid gap-8 p-6 md:grid-cols-2">
          {details.length > 0 && (
            <section aria-labelledby="details">
              <h2 id="details" className="mb-3 text-xl">{t.details}</h2>
              <dl className="divide-y" style={{ borderColor: 'var(--c-border)' }}>
                {details.map((a) => (
                  <div key={a.code} className="flex justify-between gap-4 py-2 text-sm" style={{ borderColor: 'var(--c-border)' }}>
                    <dt className="font-medium" style={{ color: 'var(--c-heading)' }}>{a.name}</dt>
                    <dd>{a.values.map((v) => v.label).join(', ')}</dd>
                  </div>
                ))}
              </dl>
            </section>
          )}
          {p.description && (
            <section aria-labelledby="desc">
              <h2 id="desc" className="mb-3 text-xl">{t.description}</h2>
              <div className="whitespace-pre-line text-sm leading-relaxed">{p.description}</div>
            </section>
          )}
        </div>
      )}

      {related.length > 0 && (
        <section className="mt-12" aria-labelledby="related">
          <h2 id="related" className="mb-5 text-2xl">{t.related}</h2>
          <ul className="product-grid">{related.map((r) => <li key={r.id}><ProductCard product={r} locale={locale} /></li>)}</ul>
        </section>
      )}
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd).replace(/</g, '\\u003c') }} />
    </div>
  )
}

export const productSegments = Object.fromEntries(Object.entries(LOCALES).map(([l, v]) => [l, v.product]))
