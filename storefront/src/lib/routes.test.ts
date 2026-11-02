import { describe, expect, it } from 'vitest'

import { href, localeFromPrefix, LOCALES } from './config'

describe('routes', () => {
  it('follows the scheme of the API (/{prefix}/{segment}/{slug})', () => {
    expect(href.product('es-MX', 'liston-rojo')).toBe('/es-mx/productos/liston-rojo')
    expect(href.category('en-US', 'ribbons')).toBe('/en-us/categories/ribbons')
    expect(href.page('en-US', '/cart')).toBe('/en-us/cart')
  })
  it('maps prefixes back to locales', () => {
    for (const l of Object.keys(LOCALES) as (keyof typeof LOCALES)[]) expect(localeFromPrefix(LOCALES[l].prefix)).toBe(l)
    expect(localeFromPrefix('fr-fr')).toBeNull()
  })
})
