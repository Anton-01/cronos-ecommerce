import 'server-only'

import { cookies } from 'next/headers'
import { notFound } from 'next/navigation'
import { cache } from 'react'

import { localeFromPrefix, type Locale } from './config'
import { getMarketContext, getMarkets, type MarketContext } from './data'
import { pickMarket } from './market'
import type { Market } from './types'

export function resolveLocale(prefix: string): Locale {
  return localeFromPrefix(prefix) ?? notFound()
}

/**
 * The store the visitor browses, decided from cookies and the cached list of stores: no call to the API per page. The API is asked only in
 * the rare case that the notice "you are browsing another store" must be shown (for its text).
 */
export const getMarket = cache(async (): Promise<{ market: Market; markets: Market[]; context: MarketContext }> => {
  const jar = await cookies()
  const markets = await getMarkets()
  const { market, detected, outside } = pickMarket(markets, jar.get('market')?.value ?? null, jar.get('geo')?.value ?? null, jar.get('market_ok')?.value ?? null)
  if (!outside || !detected) return { market, markets, context: { detectedCountry: jar.get('geo')?.value ?? null, detectedMarket: detected, selectedMarket: market, outside: false, prompt: null } }
  return { market, markets, context: await getMarketContext(market.code) }
})

export async function previewToken(): Promise<string | null> {
  return (await cookies()).get('theme_preview')?.value ?? null
}
