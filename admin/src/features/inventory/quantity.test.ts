import { describe, expect, it } from 'vitest'

import { formatQty, isValidCount, isValidQty } from './quantity'

describe('stock quantities', () => {
  it('shows three-decimal stock without trailing zeros', () => {
    expect(formatQty('12.500')).toBe('12.5')
    expect(formatQty('40.000')).toBe('40')
    expect(formatQty('0.250')).toBe('0.25')
    expect(formatQty(null)).toBe('—')
  })

  it('accepts only positive amounts with up to three decimals', () => {
    for (const ok of ['1', '0.5', '12.345', ' 7 ']) expect(isValidQty(ok)).toBe(true)
    for (const bad of ['', '0', '-1', '1.2345', 'abc', '1,5']) expect(isValidQty(bad)).toBe(false)
  })

  it('lets a count be zero', () => {
    expect(isValidCount('0')).toBe(true)
    expect(isValidCount('0.000')).toBe(true)
    expect(isValidCount('-1')).toBe(false)
  })
})
