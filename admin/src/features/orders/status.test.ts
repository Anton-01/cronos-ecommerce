import { describe, expect, it } from 'vitest'

import { customLabel, lineAmount, orderLabel, orderNeedsAttention, paymentLabel, shipmentLabel, validQuoteLine } from './status'

describe('labels', () => {
  it('names known statuses and passes unknown ones through', () => {
    expect(orderLabel('processing')).toBe('En preparación')
    expect(paymentLabel('partially_refunded')).toBe('Reembolso parcial')
    expect(shipmentLabel('out_for_delivery')).toBe('En reparto')
    expect(customLabel('quoted')).toBe('Cotizada')
    expect(orderLabel('mystery')).toBe('mystery')
  })
})

describe('attention', () => {
  it('flags disputes and cancelled orders that still hold money', () => {
    expect(orderNeedsAttention({ status: 'processing', paymentStatus: 'disputed' })).toBe(true)
    expect(orderNeedsAttention({ status: 'cancelled', paymentStatus: 'paid' })).toBe(true)
    expect(orderNeedsAttention({ status: 'cancelled', paymentStatus: 'refunded' })).toBe(false)
    expect(orderNeedsAttention({ status: 'shipped', paymentStatus: 'paid' })).toBe(false)
  })
})

describe('quote lines', () => {
  it('previews the amount and rejects incomplete lines', () => {
    expect(lineAmount('10', '50.25')).toBe(502.5)
    expect(lineAmount('0', '50')).toBe(0)
    expect(lineAmount('x', '50')).toBe(0)
    expect(validQuoteLine({ description: 'Listón', quantity: '2.5', unitPrice: '10' })).toBe(true)
    expect(validQuoteLine({ description: ' ', quantity: '2', unitPrice: '10' })).toBe(false)
    expect(validQuoteLine({ description: 'x', quantity: '0', unitPrice: '10' })).toBe(false)
    expect(validQuoteLine({ description: 'x', quantity: '1', unitPrice: '' })).toBe(false)
  })
})
