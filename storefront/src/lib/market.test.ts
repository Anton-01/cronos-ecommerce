import { describe, expect, it } from 'vitest'

import { pickMarket } from './market'
import type { Market } from './types'

const m = (code: string, country: string): Market => ({ code, name: code, countryCode: country, defaultLocale: 'es-MX', pricesIncludeTax: true, currency: { code: 'X', symbol: '$', decimals: 2 }, profile: { tradeName: null, contactEmail: null, contactPhone: null, supportHours: null, countries: [country] } })
const markets = [m('MX', 'MX'), m('US', 'US')]

describe('pickMarket', () => {
  it('starts in the store of the visitor country', () => {
    expect(pickMarket(markets, null, 'US', null)).toMatchObject({ market: { code: 'US' }, outside: false })
  })
  it('falls back to the first store for a country without one', () => {
    expect(pickMarket(markets, null, 'FR', null)).toMatchObject({ market: { code: 'MX' }, detected: null, outside: false })
  })
  it('asks once when someone browses another store, and remembers the answer for that country', () => {
    expect(pickMarket(markets, 'US', 'MX', null).outside).toBe(true)
    expect(pickMarket(markets, 'US', 'MX', 'MX').outside).toBe(false)
    expect(pickMarket(markets, 'US', 'CA', 'MX').outside).toBe(false) // CA has no store of its own: nothing to compare
  })
  it('ignores a remembered store that no longer exists', () => {
    expect(pickMarket(markets, 'ZZ', 'MX', null).market.code).toBe('MX')
  })
})
