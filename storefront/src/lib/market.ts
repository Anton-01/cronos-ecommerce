import type { Market } from './types'

export interface Pick {
  market: Market
  /** The store of the visitor's own country (from the IP), when it is known. */
  detected: Market | null
  /** Browsing a store other than the one of their country, and they have not said that is fine. */
  outside: boolean
}

/**
 * Decides the store from what the browser already remembers, with no call to the API:
 *  - `cookieMarket`: the store they chose (or the one of their country, written on their first visit);
 *  - `geo`: the country the API found from their IP on that first visit (re-read daily);
 *  - `decidedFor`: the country they last answered the "you are in another store" notice for.
 */
export function pickMarket(markets: Market[], cookieMarket: string | null, geo: string | null, decidedFor: string | null): Pick {
  const byCode = (code: string | null) => markets.find((m) => m.code === code) ?? null
  const detected = geo ? (markets.find((m) => m.profile.countries.includes(geo) || m.countryCode === geo) ?? null) : null
  const market = byCode(cookieMarket) ?? detected ?? markets[0]
  if (!market) throw new Error('The shop has no active market yet.')
  const outside = detected !== null && market.code !== detected.code && decidedFor !== geo
  return { market, detected, outside }
}
