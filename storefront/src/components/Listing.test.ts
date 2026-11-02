import { describe, expect, it } from 'vitest'

import { isRefined, listingUrl, parseQuery } from './Listing'

describe('listing query', () => {
  it('round-trips filters through the URL, repeating a parameter for each chosen value', () => {
    const q = parseQuery({ f_color: ['rojo', 'azul'], f_width: '25mm', min: '10', max: '99.5', sort: 'BEST_SELLING', page: '2' })
    expect(q).toMatchObject({ attrs: { color: ['rojo', 'azul'], width: ['25mm'] }, priceMin: '10', priceMax: '99.5', sort: 'BEST_SELLING', page: 2 })
    expect(listingUrl('/c', q, {})).toBe('/c?sort=BEST_SELLING&min=10&max=99.5&f_color=rojo&f_color=azul&f_width=25mm&page=2')
  })
  it('drops what is not a plausible filter instead of passing it to the API', () => {
    const q = parseQuery({ f_color: '<script>', 'f_bad key': 'x', min: 'abc', sort: 'DROP TABLE', page: '-3' })
    expect(q).toMatchObject({ attrs: {}, priceMin: undefined, sort: 'NEWEST', page: 1 })
  })
  it('changing a filter goes back to page 1 and only a clean listing is indexable', () => {
    const q = parseQuery({ f_color: 'rojo', page: '3' })
    expect(listingUrl('/c', q, { page: 1, attrs: {} })).toBe('/c')
    expect(isRefined(q)).toBe(true)
    expect(isRefined(parseQuery({}))).toBe(false)
  })
})
