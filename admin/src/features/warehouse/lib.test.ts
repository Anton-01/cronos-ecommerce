import { describe, expect, it } from 'vitest'

import { formatCode, itemQrPayload, locationQrPayload, newKey, normalizeCode, parseScanned, signed, toCsv } from './lib'

// A code issued by the server (Crockford base32, 10 characters + check character), generated with the same algorithm.
const body = '7K3M9Q2XA4'
const make = (b: string) => {
  const alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
  let sum = 0
  for (let i = 0; i < b.length; i++) sum += alphabet.indexOf(b[i]!) * (i + 1)
  return b + alphabet[sum % 32]
}
const code = make(body)

describe('stock codes', () => {
  it('accepts lower case, hyphens, spaces and look-alikes', () => {
    expect(normalizeCode(code)).toBe(code)
    expect(normalizeCode(formatCode(code).toLowerCase())).toBe(code)
    expect(normalizeCode(` ${code.slice(0, 5)} ${code.slice(5)} `)).toBe(code)
    // 0 and 1 are what O and I/L are read as
    const withZero = make('0000000000')
    expect(normalizeCode(withZero.replace(/0/g, 'O'))).toBe(withZero)
  })

  it('rejects a mistyped character, the wrong length and foreign characters', () => {
    const bad = (code[0] === '2' ? '3' : '2') + code.slice(1)
    expect(normalizeCode(bad)).toBeNull()
    expect(normalizeCode(code.slice(0, 10))).toBeNull()
    expect(normalizeCode('hello world')).toBeNull()
    expect(normalizeCode('')).toBeNull()
  })

  it('reads a QR url, a bare code and a shelf url', () => {
    expect(parseScanned(`https://admin.shop.test/w/${code}`)).toEqual({ kind: 'item', code })
    expect(parseScanned(`https://admin.shop.test/w/${formatCode(code)}?x=1`)).toEqual({ kind: 'item', code })
    expect(parseScanned(code)).toEqual({ kind: 'item', code })
    expect(parseScanned('https://admin.shop.test/w/loc/e03')).toEqual({ kind: 'location', code: 'E03' })
    expect(parseScanned('https://elsewhere.test/page')).toBeNull()
  })

  it('puts only the code in the QR', () => {
    expect(itemQrPayload('https://admin.shop.test/', code)).toBe(`https://admin.shop.test/w/${code}`)
    expect(locationQrPayload('https://admin.shop.test', 'E03')).toBe('https://admin.shop.test/w/loc/E03')
  })
})

describe('helpers', () => {
  it('makes a different idempotency key every time', () => {
    expect(newKey()).not.toBe(newKey())
  })

  it('writes CSV with a BOM, quotes, and no spreadsheet formulas', () => {
    const csv = toCsv([{ a: 'x, "y"', b: '=SUM(A1)' }, { a: 'plain', b: null }], [
      { header: 'A', value: (r) => r.a },
      { header: 'B', value: (r) => r.b },
    ])
    expect(csv.startsWith('﻿')).toBe(true)
    expect(csv).toContain('"x, ""y"""')
    expect(csv).toContain("'=SUM(A1)")
    expect(csv).toContain('plain,')
  })

  it('shows the sign of a quantity', () => {
    expect(signed('5.500')).toMatch(/^\+5[.,]5$/)
    expect(signed('-5.500')).toMatch(/^−5[.,]5$/)
    expect(signed('0.000')).toBe('0')
  })
})
