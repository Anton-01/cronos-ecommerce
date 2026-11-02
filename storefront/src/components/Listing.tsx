import Link from 'next/link'

import { href, type Locale } from '@/lib/config'
import type { Listing as ListingData } from '@/lib/data'
import { fill, messages } from '@/lib/i18n'

import { ProductCard } from './ProductCard'

export const PAGE_SIZE = 12
const SORTS = ['NEWEST', 'PRICE_ASC', 'PRICE_DESC', 'NAME'] as const

export interface ListingQuery { page: number; sort: string; priceMin?: string; priceMax?: string; q?: string }

/** Query-string for the same listing with some values changed. Page numbers are 1-based in the URL; the API pages by cursor, resolved in the page. */
export function listingUrl(base: string, q: ListingQuery, change: Partial<ListingQuery>): string {
  const next = { ...q, ...change }
  const params = new URLSearchParams()
  if (next.q) params.set('q', next.q)
  if (next.sort && next.sort !== 'NEWEST') params.set('sort', next.sort)
  if (next.priceMin) params.set('min', next.priceMin)
  if (next.priceMax) params.set('max', next.priceMax)
  if (next.page > 1) params.set('page', String(next.page))
  const qs = params.toString()
  return qs ? `${base}?${qs}` : base
}

export function parseQuery(sp: Record<string, string | string[] | undefined>): ListingQuery {
  const one = (k: string) => (Array.isArray(sp[k]) ? sp[k]![0] : sp[k]) as string | undefined
  const num = (v: string | undefined) => (v && /^\d+(\.\d{1,2})?$/.test(v) ? v : undefined)
  const sort = one('sort')
  return {
    page: Math.max(1, Math.min(500, parseInt(one('page') ?? '1', 10) || 1)),
    sort: SORTS.includes(sort as (typeof SORTS)[number]) || sort === 'RELEVANCE' ? (sort as string) : 'NEWEST',
    priceMin: num(one('min')),
    priceMax: num(one('max')),
    q: one('q')?.trim().slice(0, 100) || undefined,
  }
}

export function ListingView({ data, locale, base, query, currency, withSidebar }: { data: ListingData; locale: Locale; base: string; query: ListingQuery; currency: string; withSidebar: boolean }) {
  const t = messages(locale)
  const from = (query.page - 1) * PAGE_SIZE + 1
  const to = from + data.items.length - 1
  const pages = Math.max(1, Math.ceil(data.total / PAGE_SIZE))
  const filtered = Boolean(query.priceMin || query.priceMax)
  const sorts = query.q ? ['RELEVANCE', ...SORTS] : SORTS

  return (
    <div className="container-x mt-6 grid gap-6 lg:grid-cols-[260px_1fr]">
      <aside aria-label={t.filters} className="card h-fit p-4">
        <details open className="lg:[&>summary]:pointer-events-none" >
          <summary className="cursor-pointer text-base font-semibold" style={{ color: 'var(--c-heading)', fontFamily: 'var(--font-heading)' }}>{t.filters}</summary>
          <form action={base} method="get" className="mt-4 space-y-3">
            {query.q && <input type="hidden" name="q" value={query.q} />}
            {query.sort !== 'NEWEST' && <input type="hidden" name="sort" value={query.sort} />}
            <fieldset>
              <legend className="mb-2 text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{t.price} ({currency})</legend>
              <div className="flex gap-2">
                <input name="min" inputMode="decimal" aria-label={t.min} placeholder={t.min} defaultValue={query.priceMin} className="field" />
                <input name="max" inputMode="decimal" aria-label={t.max} placeholder={t.max} defaultValue={query.priceMax} className="field" />
              </div>
            </fieldset>
            <div className="flex gap-2">
              <button className="btn btn-primary" type="submit">{t.apply}</button>
              {filtered && <Link href={listingUrl(base, query, { priceMin: undefined, priceMax: undefined, page: 1 })} className="btn btn-ghost">{t.clear}</Link>}
            </div>
          </form>
        </details>
      </aside>
      <div>
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm">
          <p role="status">{data.total > 0 ? fill(t.showing, { from, to, total: data.total }) : t.noResults}</p>
          <nav aria-label={t.sortBy} className="flex flex-wrap gap-2">
            {sorts.map((s) => (
              <Link key={s} href={listingUrl(base, query, { sort: s, page: 1 })} aria-current={query.sort === s ? 'true' : undefined} className={`rounded-full px-3 py-1 ${query.sort === s ? 'bg-secondary text-on-secondary' : 'bg-surface'}`}>{t.sort[s as keyof typeof t.sort]}</Link>
            ))}
          </nav>
        </div>
        {data.items.length > 0 && (
          <ul className={`product-grid ${withSidebar ? 'with-sidebar' : ''}`}>
            {data.items.map((p, i) => <li key={p.id}><ProductCard product={p} locale={locale} priority={i < 4} /></li>)}
          </ul>
        )}
        {pages > 1 && (
          <nav aria-label={t.page} className="mt-8 flex items-center justify-center gap-3 text-sm">
            {query.page > 1 && <Link rel="prev" className="btn btn-ghost" href={listingUrl(base, query, { page: query.page - 1 })}>{t.previous}</Link>}
            <span>{t.page} {query.page} / {pages}</span>
            {query.page < pages && <Link rel="next" className="btn btn-ghost" href={listingUrl(base, query, { page: query.page + 1 })}>{t.next}</Link>}
          </nav>
        )}
      </div>
    </div>
  )
}

export { href }
