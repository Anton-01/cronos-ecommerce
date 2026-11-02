import 'server-only'

import { cache } from 'react'

import type { Locale } from './config'
import { gql } from './gql'
import type { CategoryNode, Market, ProductSummary, Theme } from './types'

/**
 * How long things that change only when an editor saves are kept before asking the API again (6 h). They are refreshed sooner, the moment
 * they change, by the API's notice to /api/revalidate (tag in `tags`); the time limit is only the safety net if a notice is lost.
 * Products have their own, shorter limit (PRODUCTS) because the API also tells us on every price, stock or catalogue change.
 */
export const LONG = 6 * 60 * 60

/** Listings and product pages: refreshed at once when the API says the catalogue, a price or stock moved; this is the safety net (10 min). */
export const PRODUCTS = 10 * 60

const IMAGE = `id url thumbUrl srcset alt width height`
const SUMMARY = (m: string) => `
  id type slug name shortDescription
  sales { unit step min max sellByCut sellByRoll rollLength rollPrice }
  image { ${IMAGE} }
  stockStatusIn(market: "${m}")
  pricing(market: "${m}") { market currency taxIncluded price { amount currency } listPrice { amount currency } compareAt { amount currency } onSale priceWithTax { amount currency } priceWithoutTax { amount currency } taxAmount { amount currency } rollPrice { amount currency } rollPriceWithTax { amount currency } }
`
export const productSummary = SUMMARY

const MARKET = `code name countryCode defaultLocale pricesIncludeTax currency { code symbol decimals } profile { tradeName contactEmail contactPhone supportHours countries }`

/** Cached for the request (React) and for 60 s across requests: the theme only changes when someone publishes. */
export const getTheme = cache(async (locale: Locale, market: string | null, preview: string | null): Promise<Theme> => {
  const data = await gql<{ storefrontTheme: Theme }>(
    `query($locale: String, $market: String, $preview: String) { storefrontTheme(locale: $locale, market: $market, preview: $preview) {
       version siteName tagline themeColor tokens cssVariables fontsHref assets navigation home features manifestUrl } }`,
    { locale, market, preview },
    preview ? {} : { revalidate: LONG, tags: ['theme'] },
  )
  return data.storefrontTheme
})

export const getCategoryTree = cache(async (locale: Locale): Promise<CategoryNode[]> => {
  const data = await gql<{ categoryTree: CategoryNode[] }>(
    `query($locale: String) { categoryTree(locale: $locale) { id slug name depth image { ${IMAGE} } children { id slug name depth image { ${IMAGE} } children { id slug name depth image { ${IMAGE} } children { id slug name depth } } } } }`,
    { locale },
    { revalidate: LONG, tags: ['categories'] },
  )
  return data.categoryTree
})

export const getMarkets = cache(async (): Promise<Market[]> => {
  const data = await gql<{ markets: Market[] }>(`query { markets { ${MARKET} } }`, {}, { revalidate: LONG, tags: ['markets'] })
  return data.markets
})

export interface MarketContext {
  detectedCountry: string | null
  detectedMarket: Market | null
  selectedMarket: Market | null
  outside: boolean
  prompt: { title: string; body: string; switchLabel: string; stayLabel: string } | null
}

/** Per visitor: the country comes from their IP, so this is never shared between people. */
export const getMarketContext = cache(async (selected: string | null): Promise<MarketContext> => {
  const data = await gql<{ marketContext: MarketContext }>(
    `query($selected: String) { marketContext(selected: $selected) {
       detectedCountry outside
       detectedMarket { ${MARKET} } selectedMarket { ${MARKET} }
       prompt { title body switchLabel stayLabel } } }`,
    { selected },
  )
  return data.marketContext
})

export interface ListingArgs {
  locale: Locale
  market: string
  category?: string
  search?: string
  sort?: string
  priceMin?: string
  priceMax?: string
  attributes?: Record<string, string[]>
  after?: string
  first?: number
}

export interface Listing { items: ProductSummary[]; total: number; hasNextPage: boolean; endCursor: string | null }

export async function getProducts(a: ListingArgs): Promise<Listing> {
  const data = await gql<{ products: Listing }>(
    `query($locale: String, $category: String, $search: String, $sort: ProductSort, $market: String, $priceMin: String, $priceMax: String, $attributes: [AttributeFilter!], $after: String, $first: Int) {
       products(locale: $locale, category: $category, search: $search, sort: $sort, market: $market, priceMin: $priceMin, priceMax: $priceMax, attributes: $attributes, after: $after, first: $first) {
         total hasNextPage endCursor items { ${SUMMARY(a.market)} } } }`,
    { locale: a.locale, category: a.category, search: a.search, sort: a.sort ?? 'NEWEST', market: a.market, priceMin: a.priceMin, priceMax: a.priceMax, attributes: toFilter(a.attributes), after: a.after, first: a.first ?? 12 },
    { revalidate: PRODUCTS, tags: ['products'] },
  )
  return data.products
}

export interface StaticPage { key: string; slug: string; title: string; bodyHtml: string; seoTitle: string; seoDescription: string | null; updatedAt: string | null; alternates: { locale: string; slug: string }[] }

/** A static page by its address. Cached for a day and refreshed on the 'content' tag: after the first visit nobody reaches the API for it. */
export async function getPage(locale: Locale, slug: string, market: string): Promise<StaticPage | null> {
  const data = await gql<{ page: StaticPage | null }>(
    `query($locale: String, $slug: String!, $market: String) { page(locale: $locale, slug: $slug, market: $market) { key slug title bodyHtml seoTitle seoDescription updatedAt alternates { locale slug } } }`,
    { locale, slug, market },
    { revalidate: 24 * 60 * 60, tags: ['content'] },
  )
  return data.page
}

export interface PageLinkItem { key: string; slug: string; title: string }

export const getPageLinks = cache(async (locale: Locale, market: string): Promise<PageLinkItem[]> => {
  const data = await gql<{ pages: PageLinkItem[] }>(`query($locale: String, $market: String) { pages(locale: $locale, market: $market) { key slug title } }`, { locale, market }, { revalidate: 24 * 60 * 60, tags: ['content'] })
  return data.pages
})

const toFilter = (a?: Record<string, string[]>) => Object.entries(a ?? {}).filter(([, v]) => v.length > 0).map(([code, values]) => ({ code, values }))

export interface Facets {
  total: number
  price: { min: string | null; max: string | null; currency: string }
  attributes: { code: string; name: string; type: string; values: { code: string; label: string; meta: { hex?: string } | null; count: number; selected: boolean }[] }[]
}

/** What the sidebar offers, with counts, for the same filters as the listing. Cached like the listing (same tag). */
export async function getFacets(a: Omit<ListingArgs, 'after' | 'first' | 'sort'>): Promise<Facets> {
  const data = await gql<{ productFacets: Facets }>(
    `query($locale: String, $category: String, $search: String, $market: String, $priceMin: String, $priceMax: String, $attributes: [AttributeFilter!]) {
       productFacets(locale: $locale, category: $category, search: $search, market: $market, priceMin: $priceMin, priceMax: $priceMax, attributes: $attributes) {
         total price { min max currency } attributes { code name type values { code label meta count selected } } } }`,
    { locale: a.locale, category: a.category, search: a.search, market: a.market, priceMin: a.priceMin, priceMax: a.priceMax, attributes: toFilter(a.attributes) },
    { revalidate: PRODUCTS, tags: ['products'] },
  )
  return data.productFacets
}

export interface ContactConfig { turnstileSiteKey: string | null; turnstileAction: string; topics: string[] }

/** Public key of the Cloudflare check and the list of topics: change only when an operator edits the API's settings. */
export async function getContactConfig(): Promise<ContactConfig> {
  const data = await gql<{ contactConfig: ContactConfig }>(`query { contactConfig { turnstileSiteKey turnstileAction topics } }`, {}, { revalidate: 60 * 60, tags: ['content'] })
  return data.contactConfig
}
