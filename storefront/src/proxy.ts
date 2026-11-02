import { NextResponse, type NextRequest } from 'next/server'

import { DEFAULT_LOCALE, LOCALES, prefixes, type Locale } from '@/lib/config'

const API = (process.env.API_URL ?? 'http://localhost:8000').replace(/\/$/, '')

/**
 * Anything outside a language prefix (/, /cart…) goes to the visitor's own store and language. The country is read by the API from the
 * IP, never from a parameter; the choice is remembered in a cookie so the next visit does not ask again.
 */
export async function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl
  const first = pathname.split('/')[1] ?? ''
  if (prefixes().includes(first)) return NextResponse.next()

  let locale: Locale = DEFAULT_LOCALE
  let market = request.cookies.get('market')?.value ?? null
  try {
    const ip = request.headers.get('x-forwarded-for')
    const response = await fetch(`${API}/graphql`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...(ip ? { 'X-Forwarded-For': ip } : {}) },
      body: JSON.stringify({ query: 'query($m: String) { marketContext(selected: $m) { selectedMarket { code defaultLocale } detectedMarket { code defaultLocale } } }', variables: { m: market } }),
      signal: AbortSignal.timeout(2500),
    })
    const ctx = ((await response.json()) as { data?: { marketContext?: { selectedMarket?: { code: string; defaultLocale: string } | null; detectedMarket?: { code: string; defaultLocale: string } | null } } }).data?.marketContext
    const chosen = ctx?.selectedMarket ?? ctx?.detectedMarket ?? null
    if (chosen) {
      market = market ?? chosen.code
      if (chosen.defaultLocale in LOCALES) locale = chosen.defaultLocale as Locale
    }
  } catch {
    // The API being slow must never block the shop: fall back to the default language.
  }

  const target = request.nextUrl.clone()
  target.pathname = `/${LOCALES[locale].prefix}${pathname === '/' ? '' : pathname}`
  target.search = search
  const redirect = NextResponse.redirect(target)
  if (market && !request.cookies.get('market')) redirect.cookies.set('market', market, { path: '/', sameSite: 'lax', maxAge: 60 * 60 * 24 * 365 })
  return redirect
}

export const config = { matcher: ['/((?!api|_next|favicon.ico|manifest.webmanifest|robots.txt|sitemap.xml|.*\\..*).*)'] }
