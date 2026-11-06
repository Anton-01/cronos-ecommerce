import { describe, expect, it } from 'vitest'

import { codeFrom, fromSheet, isEmptyValue, labelOf, missingRequired, stateOf, toPayload } from './specs'

describe('data sheet states', () => {
  it('treats empty text, empty lists and measures without a number as empty', () => {
    expect([null, undefined, '', [], { value: '', unit: 'g' }, { value: null, unit: 'g' }].every(isEmptyValue)).toBe(true)
    expect([0, 'Liso', ['a'], { value: '0', unit: 'g' }].some(isEmptyValue)).toBe(false)
  })

  it('lets a value win over a stored state, and defaults an empty field to pending', () => {
    expect(stateOf('design', { design: 'Liso' }, { design: 'not_applicable' })).toBe('value')
    expect(stateOf('design', {}, { design: 'not_applicable' })).toBe('not_applicable')
    expect(stateOf('design', {}, {})).toBe('pending')
    expect(stateOf('design', {}, { design: 'value' })).toBe('pending')
  })

  it('names the required fields still pending ("not applicable" is an answer)', () => {
    expect(missingRequired(['design', 'packaging_type', 'base_unit'], { base_unit: 'metro' }, { design: 'not_applicable' })).toEqual(['packaging_type'])
  })

  it('builds the payload: clears empty fields and carries what the emptiness means', () => {
    const p = toPayload(['design', 'weight', 'height'], { design: 'Liso', weight: { value: '', unit: 'g' } }, { height: 'not_applicable' })
    expect(p.specs).toEqual({ design: 'Liso', weight: null, height: null })
    expect(p.states).toEqual({ design: 'pending', weight: 'pending', height: 'not_applicable' })
  })

  it('reads a sheet from the API back into values and states', () => {
    const { values, states } = fromSheet([
      { key: 'design', state: 'value', value: 'Liso' },
      { key: 'weight', state: 'pending', value: null },
    ])
    expect(values).toEqual({ design: 'Liso' })
    expect(states).toEqual({ design: 'value', weight: 'pending' })
  })
})

describe('list helpers', () => {
  it('makes a code from a name without accents or spaces', () => {
    expect(codeFrom('Azul cielo')).toBe('azul_cielo')
    expect(codeFrom('  Arcoíris! ')).toBe('arcoiris')
    expect(codeFrom('Poliéster 100 %')).toBe('poliester_100')
  })

  it('picks the label of a language, or the first one there is', () => {
    const labels = [{ locale: 'en-US', value: 'Single' }, { locale: 'es-MX', value: 'Unitario' }]
    expect(labelOf(labels, 'es-MX')).toBe('Unitario')
    expect(labelOf(labels, 'fr-FR')).toBe('Single')
  })
})
