import 'server-only'

import { cookies } from 'next/headers'
import { notFound } from 'next/navigation'
import { cache } from 'react'

import { localeFromPrefix, type Locale } from './config'
import { getMarketContext, getMarkets } from './data'
import type { Market } from './types'

export function resolveLocale(prefix: string): Locale {
  return localeFromPrefix(prefix) ?? notFound()
}

/** The store the visitor browses: the one they chose, else the one of their country (from the IP), else the first active one. */
export const getMarket = cache(async (): Promise<{ market: Market; markets: Market[]; context: Awaited<ReturnType<typeof getMarketContext>> }> => {
  const selected = (await cookies()).get('market')?.value ?? null
  const [markets, context] = await Promise.all([getMarkets(), getMarketContext(selected)])
  const market = context.selectedMarket ?? context.detectedMarket ?? markets[0]
  if (!market) throw new Error('The shop has no active market yet.')
  return { market, markets, context }
})

export async function previewToken(): Promise<string | null> {
  return (await cookies()).get('theme_preview')?.value ?? null
}
