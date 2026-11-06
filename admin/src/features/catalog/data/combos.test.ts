import { describe, expect, it } from 'vitest'

import { cartesian, comboKey } from './combos'

describe('combinations', () => {
  it('pairs every value of every axis', () => {
    expect(cartesian([['rojo', 'azul'], ['25', '10']])).toEqual([['rojo', '25'], ['rojo', '10'], ['azul', '25'], ['azul', '10']])
  })

  it('skips an axis with nothing picked and gives nothing when no axis was used', () => {
    expect(cartesian([['rojo'], [], ['mate', 'brillante']])).toEqual([['rojo', 'mate'], ['rojo', 'brillante']])
    expect(cartesian([[], []])).toEqual([])
  })

  it('keys a combination the same whatever the order', () => {
    expect(comboKey(['b', 'a'])).toBe(comboKey(['a', 'b']))
  })
})
