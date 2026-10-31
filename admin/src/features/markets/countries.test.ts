import { describe, expect, it } from 'vitest'

import { countryName, parseCountries, readiness, taxIdHint } from './countries'

describe('parseCountries', () => {
  it('upper-cases, de-duplicates and splits on commas or spaces', () => {
    expect(parseCountries('mx, us ,MX;pr')).toEqual({ countries: ['MX', 'US', 'PR'], invalid: [] })
  })

  it('reports what is not a two-letter code instead of guessing', () => {
    expect(parseCountries('MX, USA, 1')).toEqual({ countries: ['MX'], invalid: ['USA', '1'] })
    expect(parseCountries('')).toEqual({ countries: [], invalid: [] })
  })
})

describe('hints', () => {
  it('words the tax id rule of each country', () => {
    expect(taxIdHint('MX')).toContain('RFC')
    expect(taxIdHint('US')).toContain('EIN')
    expect(taxIdHint('CO')).toContain('4 a 30')
    expect(countryName('MX')).toBe('México')
    expect(countryName('CO')).toBe('CO')
  })
})

describe('readiness', () => {
  const ok = { legalName: 'X', taxId: 'Y', warehouses: [{ isActive: true }], paymentProviders: ['stripe'], taxRateCount: 1 }

  it('is empty when the store has everything it needs to sell', () => {
    expect(readiness(ok)).toEqual([])
  })

  it('lists what is missing', () => {
    expect(readiness({ ...ok, taxId: null, warehouses: [{ isActive: false }], paymentProviders: [], taxRateCount: 0 })).toEqual(['datos fiscales', 'almacén activo', 'método de pago', 'tasa de impuesto'])
  })
})
