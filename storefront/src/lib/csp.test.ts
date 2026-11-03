import { describe, expect, it } from 'vitest'

import { buildCsp, imgOrigins, newNonce } from './csp'

describe('csp', () => {
  const csp = (o = {}) => buildCsp({ nonce: 'abc', imgOrigins: ['https://cdn.example'], ...o })
  it('ties scripts and styles to the nonce and forbids everything else inline', () => {
    const p = csp()
    expect(p).toContain("script-src 'self' 'nonce-abc' 'strict-dynamic'")
    expect(p).toContain("style-src 'self' 'nonce-abc'")
    expect(p).not.toMatch(/script-src[^;]*'unsafe-inline'/)
    expect(p).not.toMatch(/script-src[^;]*'unsafe-eval'/)
    expect(p).toContain("object-src 'none'")
    expect(p).toContain("frame-ancestors 'self'")
  })
  it('allows Cloudflare Turnstile and Stripe, and nothing else third-party for scripts', () => {
    const script = csp().split('; ').find((d) => d.startsWith('script-src'))!
    expect(script).toContain('https://challenges.cloudflare.com')
    expect(script).toContain('https://js.stripe.com')
    expect(script.match(/https:\/\/[^ ]+/g)).toEqual(['https://challenges.cloudflare.com', 'https://js.stripe.com'])
  })
  it('only the development policy may use eval (hot reload)', () => {
    expect(csp({ dev: true })).toContain("'unsafe-eval'")
    expect(csp()).toContain('upgrade-insecure-requests')
    expect(csp({ dev: true })).not.toContain('upgrade-insecure-requests')
  })
  it('lists picture origins and ignores malformed ones', () => {
    expect(csp()).toContain("img-src 'self' data: blob: https://cdn.example")
    expect(imgOrigins('http://localhost:8099/x', 'https://a.example/p, not a url')).toEqual(['http://localhost:8099', 'https://a.example'])
  })
  it('makes a different nonce each time', () => {
    expect(newNonce()).not.toBe(newNonce())
    expect(newNonce()).toMatch(/^[A-Za-z0-9+/=]{24}$/)
  })
})
