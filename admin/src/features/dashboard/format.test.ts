import { describe, expect, it } from 'vitest'

import { ATTENTION_LABEL, bars, change, money } from './format'

describe('money', () => {
  it('formats exact decimal strings in the store currency', () => {
    expect(money('1234.5', 'MXN')).toMatch(/1,234\.50/)
    expect(money('10', 'USD', 'en-US')).toContain('10.00')
  })

  it('never throws on odd input', () => {
    expect(money('abc', 'MXN')).toBe('abc')
    expect(money('5', 'NOPE')).toMatch(/5\.00/)
  })
})

describe('change', () => {
  it('words up, down, flat and nothing-to-compare', () => {
    expect(change(12.5)).toEqual({ text: '+12.5 %', tone: 'up' })
    expect(change(-3)).toEqual({ text: '−3 %', tone: 'down' })
    expect(change(0)).toEqual({ text: '0 %', tone: 'flat' })
    expect(change(null)).toEqual({ text: '—', tone: 'none' })
    expect(change(undefined).tone).toBe('none')
  })
})

describe('bars', () => {
  it('scales to the best day and keeps small non-zero days visible', () => {
    const b = bars([
      { day: '2026-03-01', revenue: '0.00', orders: 0 },
      { day: '2026-03-02', revenue: '1.00', orders: 1 },
      { day: '2026-03-03', revenue: '1000.00', orders: 5 },
    ])
    expect(b.map((x) => x.height)).toEqual([0, 4, 100])
  })

  it('is flat when there are no sales at all', () => {
    expect(bars([{ day: 'd', revenue: '0.00', orders: 0 }])[0]!.height).toBe(0)
    expect(bars([])).toEqual([])
  })
})

describe('attention', () => {
  it('has a sentence and a place to go for every key the server can send', () => {
    for (const key of ['checkouts_need_review', 'payments_with_anomaly', 'payments_disputed', 'orders_disputed', 'orders_cancelled_not_refunded', 'webhooks_stuck', 'notifications_failed', 'outbox_stuck', 'orders_unshipped_3d', 'custom_requests_waiting', 'quotes_expiring_3d', 'low_stock_products']) {
      expect(ATTENTION_LABEL[key]?.text).toBeTruthy()
      expect(ATTENTION_LABEL[key]?.to.startsWith('/')).toBe(true)
    }
  })
})
