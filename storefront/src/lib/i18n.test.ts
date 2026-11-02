import { describe, expect, it } from 'vitest'

import { fill, messages } from './i18n'

describe('i18n', () => {
  it('has the same keys in both languages', () => {
    const keys = (o: object): string[] => Object.entries(o).flatMap(([k, v]) => (typeof v === 'object' ? keys(v).map((x) => `${k}.${x}`) : [k]))
    expect(keys(messages('en-US')).sort()).toEqual(keys(messages('es-MX')).sort())
  })
  it('fills placeholders', () => {
    expect(fill('{from}–{to} de {total}', { from: 1, to: 12, total: 40 })).toBe('1–12 de 40')
  })
})
