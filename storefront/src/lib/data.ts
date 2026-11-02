import 'server-only'

import { cache } from 'react'

import type { Locale } from './config'
import { gql } from './gql'
import type { CategoryNode, Market, ProductSummary, Theme } from './types'

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
    preview ? {} : { revalidate: 60, tags: ['theme'] },
  )
  return data.storefrontTheme
})

export const getCategoryTree = cache(async (locale: Locale): Promise<CategoryNode[]> => {
  const data = await gql<{ categoryTree: CategoryNode[] }>(
    `query($locale: String) { categoryTree(locale: $locale) { id slug name depth image { ${IMAGE} } children { id slug name depth image { ${IMAGE} } children { id slug name depth image { ${IMAGE} } children { id slug name depth } } } } }`,
    { locale },
    { revalidate: 120, tags: ['categories'] },
  )
  return data.categoryTree
})

export const getMarkets = cache(async (): Promise<Market[]> => {
  const data = await gql<{ markets: Market[] }>(`query { markets { ${MARKET} } }`, {}, { revalidate: 120, tags: ['markets'] })
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
  after?: string
  first?: number
}

export interface Listing { items: ProductSummary[]; total: number; hasNextPage: boolean; endCursor: string | null }

export async function getProducts(a: ListingArgs): Promise<Listing> {
  const data = await gql<{ products: Listing }>(
    `query($locale: String, $category: String, $search: String, $sort: ProductSort, $priceMin: String, $priceMax: String, $after: String, $first: Int) {
       products(locale: $locale, category: $category, search: $search, sort: $sort, priceMin: $priceMin, priceMax: $priceMax, after: $after, first: $first) {
         total hasNextPage endCursor items { ${SUMMARY(a.market)} } } }`,
    { locale: a.locale, category: a.category, search: a.search, sort: a.sort ?? 'NEWEST', priceMin: a.priceMin, priceMax: a.priceMax, after: a.after, first: a.first ?? 12 },
    { revalidate: 60, tags: ['products'] },
  )
  return data.products
}
