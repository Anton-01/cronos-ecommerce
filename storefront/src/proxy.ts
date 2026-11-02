import { NextResponse, type NextRequest } from 'next/server'

import { DEFAULT_LOCALE, LOCALES, prefixes, type Locale } from '@/lib/config'

const API = (process.env.API_URL ?? 'http://localhost:8000').replace(/\/$/, '')
const DAY = 60 * 60 * 24

interface Detected { country: string | null; market: string | null; locale: Locale | null }

async function detect(request: NextRequest): Promise<Detected> {
  try {
    const ip = request.headers.get('x-forwarded-for')
    const response = await fetch(`${API}/graphql`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', ...(ip ? { 'X-Forwarded-For': ip } : {}) },
      body: JSON.stringify({ query: '{ marketContext { detectedCountry detectedMarket { code defaultLocale } } }' }),
      signal: AbortSignal.timeout(2500),
    })
    const ctx = ((await response.json()) as { data?: { marketContext?: { detectedCountry: string | null; detectedMarket: { code: string; defaultLocale: string } | null } } }).data?.marketContext
    const lang = ctx?.detectedMarket?.defaultLocale
    return { country: ctx?.detectedCountry ?? null, market: ctx?.detectedMarket?.code ?? null, locale: lang && lang in LOCALES ? (lang as Locale) : null }
  } catch {
    return { country: null, market: null, locale: null } // a slow API must never block the shop
  }
}

/**
 * First contact: the country comes from the IP (decided by the API, never from a parameter) and is remembered in cookies, so every later
 * page needs no call to the API to know the store. `/` and other paths without a language go to the visitor's store and language.
 */
export async function proxy(request: NextRequest) {
  const { pathname, search } = request.nextUrl
  const first = pathname.split('/')[1] ?? ''
  const hasPrefix = prefixes().includes(first)
  const needsGeo = !request.cookies.has('geo')
  // One call per day per visitor (never per page). Unknown countries (a local network) are remembered as "-" so they are not asked again each time.
  const found = needsGeo ? await detect(request) : { country: null, market: null, locale: null }
  const cookies: Record<string, string> = {}
  if (needsGeo) {
    cookies.geo = found.country ?? '-'
    if (found.market && !request.cookies.has('market')) cookies.market = found.market
    if (found.locale) cookies.lang = found.locale
  }

  let response: NextResponse
  if (hasPrefix) {
    // The page being rendered right now must already see the cookies we are about to set.
    const headers = new Headers(request.headers)
    const merged = [request.headers.get('cookie'), ...Object.entries(cookies).map(([k, v]) => `${k}=${v}`)].filter(Boolean).join('; ')
    merged && headers.set('cookie', merged)
    response = NextResponse.next({ request: { headers } })
  } else {
    const remembered = found.locale ?? request.cookies.get('lang')?.value
    const locale = remembered && remembered in LOCALES ? (remembered as Locale) : DEFAULT_LOCALE
    const target = request.nextUrl.clone()
    target.pathname = `/${LOCALES[locale].prefix}${pathname === '/' ? '' : pathname}`
    target.search = search
    response = NextResponse.redirect(target)
  }
  if (cookies.geo) response.cookies.set('geo', cookies.geo, { path: '/', sameSite: 'lax', maxAge: cookies.geo === '-' ? 60 * 60 : DAY }) // re-read daily: people travel
  if (cookies.lang) response.cookies.set('lang', cookies.lang, { path: '/', sameSite: 'lax', maxAge: DAY * 365 })
  if (cookies.market) response.cookies.set('market', cookies.market, { path: '/', sameSite: 'lax', maxAge: DAY * 365 })
  return response
}

export const config = { matcher: ['/((?!api|_next|favicon.ico|manifest.webmanifest|robots.txt|sitemap.xml|.*\\..*).*)'] }
