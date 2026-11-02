import { describe, expect, it } from 'vitest'

import { moveSection, newSection, removeSection, setSection, withLocale } from './sections'
import type { HomeSection } from './types'

const mk = (id: string): HomeSection => ({ id, type: 'text', enabled: true, settings: {} })

describe('sections', () => {
  it('creates sections with unique ids and the settings of their type', () => {
    const first = newSection('hero', [])
    expect(first).toMatchObject({ id: 'hero', type: 'hero', enabled: true, settings: { autoplay: 6, slides: [] } })
    expect(newSection('hero', [first]).id).toBe('hero-2')
    expect(newSection('hero', [first, { ...first, id: 'hero-2' }]).id).toBe('hero-3')
    expect(newSection('products', []).settings).toMatchObject({ source: 'newest', limit: 10 })
    expect(newSection('mystery', []).settings).toEqual({})
  })

  it('reorders without mutating and ignores impossible moves', () => {
    const list = [mk('a'), mk('b'), mk('c')]
    expect(moveSection(list, 0, 2).map((s) => s.id)).toEqual(['b', 'c', 'a'])
    expect(list.map((s) => s.id)).toEqual(['a', 'b', 'c'])
    expect(moveSection(list, 1, 1)).toBe(list)
    expect(moveSection(list, -1, 2)).toBe(list)
    expect(moveSection(list, 0, 9)).toBe(list)
  })

  it('patches and removes by id', () => {
    const list = [mk('a'), mk('b')]
    expect(setSection(list, 'b', { enabled: false })[1]!.enabled).toBe(false)
    expect(removeSection(list, 'a').map((s) => s.id)).toEqual(['b'])
  })

  it('replaces one language of a text and keeps the rest', () => {
    expect(withLocale({ 'es-MX': 'Hola', 'en-US': 'Hi' }, 'en-US', 'Hello')).toEqual({ 'es-MX': 'Hola', 'en-US': 'Hello' })
    expect(withLocale(undefined, 'es-MX', 'x')).toEqual({ 'es-MX': 'x' })
  })
})
