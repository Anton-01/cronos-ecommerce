import { describe, expect, it } from 'vitest'

import { clampToStep, isMultipleOf, money } from './format'

describe('format', () => {
  it('formats money in the visitor language and the market currency', () => {
    expect(money('116.00', 'MXN', 'es-MX')).toContain('116.00')
    expect(money('9.5', 'USD', 'en-US')).toBe('$9.50')
  })
  it('knows what a valid cut is', () => {
    expect(isMultipleOf(2.5, 0.5)).toBe(true)
    expect(isMultipleOf(2.3, 0.5)).toBe(false)
    expect(isMultipleOf(0.3, 0.1)).toBe(true) // floating point noise must not reject it
  })
  it('snaps a typed length to the step and the limits', () => {
    expect(clampToStep(2.3, 0.5, 0.5, 50)).toBe(2.5)
    expect(clampToStep(0, 0.5, 0.5, 50)).toBe(0.5)
    expect(clampToStep(99, 0.5, 0.5, 50)).toBe(50)
  })
})
