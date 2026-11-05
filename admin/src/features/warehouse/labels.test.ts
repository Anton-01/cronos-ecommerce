import { describe, expect, it } from 'vitest'

import { layoutLabel, wrapText } from './labels'

describe('label layout', () => {
  it('puts the QR on the left and the text beside it when the label is wide enough', () => {
    const l = layoutLabel({ widthMm: 60, heightMm: 40, qrSizeMm: 28 })
    expect(l.stacked).toBe(false)
    expect(l.qr.size).toBe(28)
    expect(l.text.x).toBeGreaterThan(l.qr.x + l.qr.size)
    expect(l.text.x + l.text.width).toBeLessThanOrEqual(60)
  })

  it('stacks the text under the QR on a narrow label', () => {
    const l = layoutLabel({ widthMm: 30, heightMm: 40, qrSizeMm: 24 })
    expect(l.stacked).toBe(true)
    expect(l.text.y).toBeGreaterThan(l.qr.y + l.qr.size)
    expect(l.text.align).toBe('center')
  })

  it('never lets the QR overflow the label', () => {
    const l = layoutLabel({ widthMm: 25, heightMm: 20, qrSizeMm: 100 })
    expect(l.qr.size).toBeLessThanOrEqual(16)
    expect(l.qr.x).toBeGreaterThanOrEqual(0)
    expect(l.qr.y + l.qr.size).toBeLessThanOrEqual(20)
  })
})

describe('wrapText', () => {
  const measure = (s: string) => s.length * 10

  it('wraps on words and keeps short text whole', () => {
    expect(wrapText('Listón satén rojo', 1000, 2, measure)).toEqual(['Listón satén rojo'])
    expect(wrapText('uno dos tres cuatro', 90, 3, measure)).toEqual(['uno dos', 'tres', 'cuatro'])
  })

  it('ends with an ellipsis when it has to cut', () => {
    const lines = wrapText('uno dos tres cuatro cinco seis siete', 90, 2, measure)
    expect(lines).toHaveLength(2)
    expect(lines[1]!.endsWith('…')).toBe(true)
  })

  it('trims a word longer than the column', () => {
    const [line] = wrapText('supercalifragilístico', 80, 1, measure)
    expect(line!.length * 10).toBeLessThanOrEqual(80)
  })
})
