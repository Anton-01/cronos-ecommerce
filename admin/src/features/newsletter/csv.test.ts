import { describe, expect, it } from 'vitest'

import { cell, toCsv } from './csv'

describe('csv', () => {
  it('quotes commas, quotes and line breaks', () => {
    expect(cell('a,b')).toBe('"a,b"')
    expect(cell('say "hi"')).toBe('"say ""hi"""')
    expect(cell('x\ny')).toBe('"x\ny"')
    expect(cell(null)).toBe('')
  })
  it('neutralises spreadsheet formulas', () => {
    expect(cell('=HYPERLINK("http://evil")')).toBe(`"'=HYPERLINK(""http://evil"")"`)
    expect(cell('+52 55')).toBe("'+52 55")
    expect(cell('ana@example.com')).toBe('ana@example.com')
  })
  it('joins rows with CRLF and ends with one', () => {
    expect(toCsv(['email', 'status'], [['a@x.com', 'confirmed']])).toBe('email,status\r\na@x.com,confirmed\r\n')
  })
})
