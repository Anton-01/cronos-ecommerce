import Link from 'next/link'

import type { Facets, Listing as ListingData } from '@/lib/data'
import type { Locale } from '@/lib/config'
import { fill, messages } from '@/lib/i18n'

import { ProductCard } from './ProductCard'

export const PAGE_SIZE = 12
const SORTS = ['NEWEST', 'BEST_SELLING', 'PRICE_ASC', 'PRICE_DESC', 'NAME'] as const
const PARAM = 'f_' // attribute filters travel as ?f_color=rojo&f_color=azul

export interface ListingQuery { page: number; sort: string; priceMin?: string; priceMax?: string; q?: string; attrs: Record<string, string[]> }

/** Query-string for the same listing with some values changed. Page numbers are 1-based in the URL; the API pages by cursor, resolved in the page. */
export function listingUrl(base: string, q: ListingQuery, change: Partial<ListingQuery>): string {
  const next = { ...q, ...change }
  const params = new URLSearchParams()
  if (next.q) params.set('q', next.q)
  if (next.sort && next.sort !== 'NEWEST') params.set('sort', next.sort)
  if (next.priceMin) params.set('min', next.priceMin)
  if (next.priceMax) params.set('max', next.priceMax)
  for (const [code, values] of Object.entries(next.attrs)) for (const v of values) params.append(PARAM + code, v)
  if (next.page > 1) params.set('page', String(next.page))
  const qs = params.toString()
  return qs ? `${base}?${qs}` : base
}

export function parseQuery(sp: Record<string, string | string[] | undefined>): ListingQuery {
  const one = (k: string) => (Array.isArray(sp[k]) ? sp[k]![0] : sp[k]) as string | undefined
  const num = (v: string | undefined) => (v && /^\d+(\.\d{1,2})?$/.test(v) ? v : undefined)
  const sort = one('sort')
  const attrs: Record<string, string[]> = {}
  for (const [key, raw] of Object.entries(sp)) {
    if (!key.startsWith(PARAM) || !/^[a-z0-9_-]{1,64}$/.test(key.slice(PARAM.length))) continue
    const values = (Array.isArray(raw) ? raw : [raw]).filter((v): v is string => typeof v === 'string' && /^[\p{L}\p{N}_.\- ]{1,64}$/u.test(v)).slice(0, 20)
    if (values.length > 0) attrs[key.slice(PARAM.length)] = values
  }
  return {
    page: Math.max(1, Math.min(500, parseInt(one('page') ?? '1', 10) || 1)),
    sort: (SORTS as readonly string[]).includes(sort ?? '') || sort === 'RELEVANCE' ? (sort as string) : 'NEWEST',
    priceMin: num(one('min')),
    priceMax: num(one('max')),
    q: one('q')?.trim().slice(0, 100) || undefined,
    attrs,
  }
}

/** A filtered or sorted listing shows the same products again: search engines should only index the clean one. */
export const isRefined = (q: ListingQuery) => q.sort !== 'NEWEST' || q.page > 1 || Boolean(q.priceMin || q.priceMax) || Object.keys(q.attrs).length > 0

export function ListingView({ data, facets, locale, base, query, withSidebar }: { data: ListingData; facets: Facets | null; locale: Locale; base: string; query: ListingQuery; withSidebar: boolean }) {
  const t = messages(locale)
  const from = (query.page - 1) * PAGE_SIZE + 1
  const to = from + data.items.length - 1
  const pages = Math.max(1, Math.ceil(data.total / PAGE_SIZE))
  const sorts = query.q ? ['RELEVANCE', ...SORTS] : SORTS
  const chips = (facets?.attributes ?? []).flatMap((a) => a.values.filter((v) => v.selected).map((v) => ({ key: `${a.code}:${v.code}`, label: `${a.name}: ${v.label}`, href: listingUrl(base, query, { attrs: { ...query.attrs, [a.code]: (query.attrs[a.code] ?? []).filter((x) => x !== v.code) }, page: 1 }) })))
  const priceChip = query.priceMin || query.priceMax ? [{ key: 'price', label: `${t.price}: ${query.priceMin ?? '…'} – ${query.priceMax ?? '…'} ${facets?.price.currency ?? ''}`, href: listingUrl(base, query, { priceMin: undefined, priceMax: undefined, page: 1 }) }] : []
  const active = [...chips, ...priceChip]

  return (
    <div className="container-x mt-6 grid gap-6 lg:grid-cols-[260px_1fr]">
      <aside aria-label={t.filters} className="card h-fit p-4">
        <details open className="lg:[&>summary]:pointer-events-none">
          <summary className="cursor-pointer text-base font-semibold" style={{ color: 'var(--c-heading)', fontFamily: 'var(--font-heading)' }}>{t.filters}</summary>
          <form action={base} method="get" className="mt-4 space-y-5">
            {query.q && <input type="hidden" name="q" value={query.q} />}
            {query.sort !== 'NEWEST' && <input type="hidden" name="sort" value={query.sort} />}
            <fieldset>
              <legend className="mb-2 text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{t.price}{facets ? ` (${facets.price.currency})` : ''}</legend>
              <div className="flex gap-2">
                <input name="min" inputMode="decimal" aria-label={t.min} placeholder={facets?.price.min ?? t.min} defaultValue={query.priceMin} className="field" />
                <input name="max" inputMode="decimal" aria-label={t.max} placeholder={facets?.price.max ?? t.max} defaultValue={query.priceMax} className="field" />
              </div>
            </fieldset>
            {facets?.attributes.map((a) => (
              <fieldset key={a.code}>
                <legend className="mb-2 text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{a.name}</legend>
                {a.type === 'color' ? (
                  <ul className="flex flex-wrap gap-2">
                    {a.values.map((v) => (
                      <li key={v.code}>
                        <label className="relative block cursor-pointer" title={`${v.label} (${v.count})`}>
                          <input type="checkbox" name={PARAM + a.code} value={v.code} defaultChecked={v.selected} className="peer sr-only" />
                          <span className="block size-8 rounded-full border-2 border-white shadow ring-1 ring-[var(--c-border)] peer-checked:ring-2 peer-checked:ring-[var(--c-primary)] peer-focus-visible:outline peer-focus-visible:outline-2" style={{ background: v.meta?.hex ?? 'var(--c-surface-alt)', opacity: v.count === 0 && !v.selected ? 0.4 : 1 }} />
                          <span className="sr-only">{v.label} ({v.count})</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                ) : (
                  <ul className="space-y-1.5">
                    {a.values.map((v) => (
                      <li key={v.code}>
                        <label className="flex cursor-pointer items-center justify-between gap-2 text-sm">
                          <span className="flex items-center gap-2"><input type="checkbox" name={PARAM + a.code} value={v.code} defaultChecked={v.selected} />{v.label}</span>
                          <span className="text-xs">{v.count}</span>
                        </label>
                      </li>
                    ))}
                  </ul>
                )}
              </fieldset>
            ))}
            <div className="flex gap-2">
              <button className="btn btn-primary" type="submit">{t.apply}</button>
              {active.length > 0 && <Link href={listingUrl(base, query, { priceMin: undefined, priceMax: undefined, attrs: {}, page: 1 })} className="btn btn-ghost">{t.clear}</Link>}
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
        {active.length > 0 && (
          <ul className="mb-4 flex flex-wrap gap-2" aria-label={t.filters}>
            {active.map((c) => <li key={c.key}><Link href={c.href} className="inline-flex items-center gap-1 rounded-full bg-primary-soft px-3 py-1 text-xs" aria-label={`${t.remove}: ${c.label}`}>{c.label} <span aria-hidden>×</span></Link></li>)}
          </ul>
        )}
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
