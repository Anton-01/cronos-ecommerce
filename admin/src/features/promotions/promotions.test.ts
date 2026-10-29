import { describe, expect, it } from 'vitest'

import { estimate } from '@/features/catalog/products/PricesTab'

import { describeCoupon } from './CouponsPage'
import { fromApiDate, toApiDate } from './dates'
import { describeAction } from './PromotionsPage'

describe('dates', () => {
  it('round-trips between the API format and datetime-local', () => {
    expect(fromApiDate('2026-06-01 12:30:00')).toBe('2026-06-01T12:30')
    expect(toApiDate('2026-06-01T12:30')).toBe('2026-06-01 12:30:00')
    expect(fromApiDate(null)).toBe('')
    expect(toApiDate('')).toBeNull()
  })
})

describe('describeCoupon', () => {
  it('words each coupon type', () => {
    expect(describeCoupon({ type: 'PERCENTAGE', value: '15.0000', currency: null })).toBe('15 %')
    expect(describeCoupon({ type: 'FIXED', value: '50', currency: 'MXN' })).toBe('50 MXN')
    expect(describeCoupon({ type: 'FREE_SHIPPING', value: '0', currency: null })).toBe('Envío gratis')
  })
})

describe('describeAction', () => {
  it('words buy-x-get-y and percentage promotions', () => {
    expect(describeAction({ __typename: 'PromotionAction', type: 'BUY_X_GET_Y', buy: 2, get: 1, percent: '100', value: null, currency: null })).toBe('Compra 2, lleva 1 con 100 % de descuento')
    expect(describeAction({ __typename: 'PromotionAction', type: 'PERCENTAGE_OFF', value: '10', currency: null, buy: null, get: null, percent: null })).toBe('10 % de descuento')
  })
})

describe('estimate', () => {
  const usd = { __typename: 'AdminCurrency' as const, id: '2', code: 'USD', name: 'US dollar', symbol: '$', decimals: 2, exchangeRate: '0.05500000', isDefault: false, isActive: true, rateUpdatedAt: null }

  it('converts the base price with the exchange rate and keeps the base currency as is', () => {
    expect(estimate('116.00', usd)).toBe('6.38')
    expect(estimate('116.00', { ...usd, isDefault: true })).toBe('116.00')
    expect(estimate(null, usd)).toBe('')
  })
})
