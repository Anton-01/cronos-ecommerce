import { describe, expect, it } from 'vitest'

import { PIECES, RIBBON, describeSales, formatCents, fromSalesApi, priceExamples, toCents, toMilli, toSalesInput, validateSales, type SalesForm } from './salesUnit'

const ribbon = (over: Partial<SalesForm> = {}): SalesForm => ({ ...RIBBON, step: '0.5', min: '1', max: '50', sellByRoll: true, rollLength: '25', rollPrice: '400.00', ...over })

describe('decimal parsing is exact', () => {
  it('reads quantities in thousandths and money in cents', () => {
    expect(toMilli('2.5')).toBe(2500)
    expect(toMilli('0.1') + toMilli('0.2')).toBe(toMilli('0.3'))
    expect(toCents('18.5')).toBe(1850)
    expect(toCents('19.99') * 3).toBe(5997)
  })

  it('rejects anything that is not a plain decimal', () => {
    for (const bad of ['', 'abc', '-1', '1,5', '1.2345', '1e3', ' ']) expect(toMilli(bad)).toBeNaN()
    expect(toCents('1.234')).toBeNaN()
  })
})

describe('validateSales', () => {
  it('accepts pieces and a ribbon sold both ways', () => {
    expect(validateSales(PIECES)).toEqual([])
    expect(validateSales(ribbon())).toEqual([])
  })

  it.each([
    ['zero step', ribbon({ step: '0' }), 'paso'],
    ['minimum off the step grid', ribbon({ min: '1.2' }), 'múltiplo del paso'],
    ['maximum below minimum', ribbon({ min: '5', max: '3' }), 'máximo no puede ser menor'],
    ['maximum off the grid', ribbon({ max: '10.3' }), 'máximo debe ser múltiplo'],
    ['rolls without a length', ribbon({ rollLength: '' }), 'metros tiene un rollo'],
    ['neither cut nor roll', ribbon({ sellByCut: false, sellByRoll: false }), 'Activa la venta'],
    ['fractional pieces', { ...PIECES, step: '0.5', min: '0.5' }, 'piezas'],
  ])('flags %s', (_name, form, fragment) => {
    expect(validateSales(form).join(' ')).toContain(fragment)
  })
})

describe('summaries and price examples', () => {
  it('describes both ways of buying in plain Spanish', () => {
    expect(describeSales(ribbon())).toEqual(['Corte libre: desde 1 m, hasta 50 m, en pasos de 0.5 m.', 'Rollo completo de 25 m a $400.00 por rollo.'])
    expect(describeSales(PIECES)).toEqual(['Se vende por pieza.'])
  })

  it('shows worked examples, including the saving of buying a whole roll', () => {
    expect(priceExamples(ribbon(), '18.00')).toEqual(['2 m de corte = $36.00', '1 rollo (25 m) = $400.00', 'Ahorro por rollo frente al corte: $50.00'])
  })

  it('warns when a roll costs more than the same length by the cut', () => {
    expect(priceExamples(ribbon({ rollPrice: '500' }), '18.00').at(-1)).toContain('cuesta más')
  })

  it('prices a roll by length when there is no explicit roll price', () => {
    expect(priceExamples(ribbon({ rollPrice: '' }), '18.00')).toContain('1 rollo (25 m) = $450.00')
  })

  it('shows nothing while the configuration or the price is invalid', () => {
    expect(priceExamples(ribbon({ step: '0' }), '18')).toEqual([])
    expect(priceExamples(ribbon(), 'abc')).toEqual([])
    expect(priceExamples(PIECES, '10')).toEqual([])
  })

  it('formats money the Mexican way', () => {
    expect(formatCents(123456)).toBe('$1,234.56')
  })
})

describe('API mapping', () => {
  it('sends only what applies to the chosen unit', () => {
    expect(toSalesInput(PIECES)).toMatchObject({ unit: 'PIECE', sellByRoll: false, rollLength: null, rollPrice: null })
    expect(toSalesInput(ribbon({ sellByRoll: false }))).toMatchObject({ unit: 'METER', sellByRoll: false, rollLength: null })
    expect(toSalesInput(ribbon())).toMatchObject({ unit: 'METER', step: '0.5', max: '50', rollLength: '25', rollPrice: '400.00' })
    expect(toSalesInput(ribbon({ max: '' })).max).toBeNull()
  })

  it('reads the server representation back into the form', () => {
    const form = fromSalesApi({ unit: 'METER', step: '0.500', min: '1.000', max: '50.000', sellByCut: true, sellByRoll: true, rollLength: '25.000', rollPrice: '400.00' })
    expect(form).toEqual(ribbon())
  })
})
