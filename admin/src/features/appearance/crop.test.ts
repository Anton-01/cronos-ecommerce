import { describe, expect, it } from 'vitest'

import { aspectFor, cropProblem, toCropInput } from './crop'

describe('toCropInput', () => {
  const natural = { width: 1000, height: 500 }

  it('rounds the fractional area to whole pixels', () => {
    expect(toCropInput({ x: 10.4, y: 20.6, width: 300.5, height: 100.2 }, natural)).toEqual({ x: 10, y: 21, width: 301, height: 100, rotate: 0, flipX: false, flipY: false, trim: false })
  })

  it('never lets the crop leave the picture, even by one pixel', () => {
    const c = toCropInput({ x: 700.4, y: 300.4, width: 300.4, height: 200.4 }, natural)
    expect(c.x + c.width).toBeLessThanOrEqual(1000)
    expect(c.y + c.height).toBeLessThanOrEqual(500)
    expect(toCropInput({ x: -5, y: -5, width: 5000, height: 5000 }, natural)).toMatchObject({ x: 0, y: 0, width: 1000, height: 500 })
  })

  it('keeps at least one pixel and normalises rotation to 0/90/180/270', () => {
    expect(toCropInput({ x: 999.9, y: 499.9, width: 0, height: 0 }, natural)).toMatchObject({ width: 1, height: 1 })
    expect(toCropInput({ x: 0, y: 0, width: 10, height: 10 }, natural, { rotate: -90 }).rotate).toBe(270)
    expect(toCropInput({ x: 0, y: 0, width: 10, height: 10 }, natural, { rotate: 450 }).rotate).toBe(90)
    expect(toCropInput({ x: 0, y: 0, width: 10, height: 10 }, natural, { flipX: true, trim: true })).toMatchObject({ flipX: true, trim: true })
  })
})

describe('slot rules', () => {
  it('uses the slot ratio, or any shape when it has none', () => {
    expect(aspectFor(1)).toBe(1)
    expect(aspectFor(2.4)).toBe(2.4)
    expect(aspectFor(null)).toBeUndefined()
  })

  it('explains a crop that is too small', () => {
    expect(cropProblem({ x: 0, y: 0, width: 100, height: 100 }, { width: 256, height: 256 })).toContain('256×256')
    expect(cropProblem({ x: 0, y: 0, width: 300, height: 300 }, { width: 256, height: 256 })).toBeNull()
  })
})
