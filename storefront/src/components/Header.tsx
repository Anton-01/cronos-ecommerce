import Link from 'next/link'

import { href, LOCALES, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'
import { money } from '@/lib/format'
import type { Cart, CategoryNode, Market, Theme } from '@/lib/types'

import { MarketSwitcher } from './MarketControls'
import { MobileMenu, type MenuItem } from './MobileMenu'

const icon = { width: 22, height: 22, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, 'aria-hidden': true } as const

export function Logo({ theme, locale, dark = false }: { theme: Theme; locale: Locale; dark?: boolean }) {
  const asset = (dark ? theme.assets.logoDark : null) ?? theme.assets.logo
  return (
    <Link href={href.home(locale)} className="inline-flex items-center" aria-label={theme.siteName}>
      {asset?.url ? (
        // eslint-disable-next-line @next/next/no-img-element -- logo renditions are produced by the API (or an SVG served as is)
        <img src={asset.url} srcSet={asset.srcset ?? undefined} alt={theme.siteName} width={asset.width ?? undefined} height={asset.height ?? undefined} className="h-9 w-auto max-w-[180px] object-contain" />
      ) : (
        <span className="text-2xl" style={{ fontFamily: 'var(--font-heading)', fontWeight: 'var(--fw-heading)', color: dark ? 'var(--c-footer-heading)' : 'var(--c-header-text)' }}>{theme.siteName}</span>
      )}
    </Link>
  )
}

/** Internal links stored in the theme ("/ruta") get the language prefix; absolute URLs are left alone. */
export const link = (locale: Locale, url: string) => {
  if (!url.startsWith('/')) return url
  // Links to the catalogue are stored once for every language: the segment (/categorias, /categories…) is swapped for the visitor's own.
  const own = LOCALES[locale]
  const path = url.replace(/^\/(categorias|categories)\//, `/${own.category}/`).replace(/^\/(productos|products)\//, `/${own.product}/`)
  return href.page(locale, path)
}

export function Header({ theme, locale, categories, markets, market, cart, ribbon, userName }: { theme: Theme; locale: Locale; categories: CategoryNode[]; markets: Market[]; market: Market; cart: Cart | null; ribbon: boolean; userName: string | null }) {
  const t = messages(locale)
  const layout = theme.tokens.layout
  const count = cart ? cart.lines.length : 0
  const items: MenuItem[] = categories.map((c) => ({ label: c.name ?? '', href: href.category(locale, c.slug ?? ''), children: c.children.map((s) => ({ label: s.name ?? '', href: href.category(locale, s.slug ?? '') })) }))
  const topLinks: MenuItem[] = theme.navigation.topbarLinks.map((l) => ({ label: l.label, href: link(locale, l.url) }))
  const announcement = theme.navigation.announcements[0]

  return (
    <header className={layout.stickyHeader ? 'sticky top-0 z-30' : ''}>
      {layout.showTopbar && (
        <div className="hidden bg-topbar text-topbar-ink lg:block">
          <div className="container-x flex h-10 items-center justify-between text-xs">
            <MarketSwitcher markets={markets.map((m) => ({ code: m.code, name: m.name }))} current={market.code} locale={locale} labels={{ store: t.store, language: t.language }} />
            <nav aria-label="Top" className="flex items-center gap-5">
              {topLinks.map((l) => <Link key={l.href + l.label} href={l.href}>{l.label}</Link>)}
              {market.profile.contactPhone && <a href={`tel:${market.profile.contactPhone}`}>{market.profile.contactPhone}</a>}
            </nav>
          </div>
        </div>
      )}
      <div className="bg-header text-header-ink">
        <div className="container-x flex h-16 items-center gap-4">
          <MobileMenu items={items} links={topLinks} searchAction={href.page(locale, 'search')} labels={{ menu: t.menu, close: t.close, search: t.search, searchPlaceholder: t.searchPlaceholder, categories: t.categories }} />
          <div className="mx-auto lg:mx-0"><Logo theme={theme} locale={locale} /></div>
          <form action={href.page(locale, 'search')} role="search" className="mx-6 hidden max-w-2xl flex-1 lg:block">
            <label className="sr-only" htmlFor="site-search">{t.search}</label>
            <input id="site-search" name="q" type="search" className="field" placeholder={t.searchPlaceholder} />
          </form>
          <nav className="ml-auto hidden items-center gap-3 lg:flex" aria-label={t.account}>
            <Link href={href.page(locale, 'wishlist')} className="p-2" aria-label={t.wishlist}><svg {...icon}><path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9Z" /></svg></Link>
            <Link href={href.page(locale, 'account')} className="btn btn-ghost">{userName ?? `${t.login} / ${t.register}`}</Link>
          </nav>
          <Link href={href.page(locale, 'cart')} className="btn btn-dark ml-auto lg:ml-0" aria-label={`${t.cart}: ${count}`}>
            {cart?.totals ? <span className="hidden sm:inline">{money(cart.totals.total, cart.totals.currency, locale)}</span> : <span className="hidden sm:inline">{t.cart}</span>}
            <svg {...icon}><path d="M6 6h15l-2 9H8L6 6Zm0 0L5 3H2M9 20a1 1 0 1 0 0 .01M18 20a1 1 0 1 0 0 .01" /></svg>
            <span className="grid min-w-5 place-items-center rounded-full bg-primary px-1 text-xs text-on-primary">{count}</span>
          </Link>
        </div>
      </div>
      {layout.showCategoryNav && (
        <div className="hidden bg-header text-header-ink lg:block" style={{ borderTop: '1px solid var(--c-border)' }}>
          <div className="container-x flex h-11 items-center justify-between">
            <nav aria-label={t.categories} className="flex gap-6 text-sm font-semibold" style={{ fontFamily: 'var(--font-heading)' }}>
              {items.slice(0, 8).map((c) => <Link key={c.href} href={c.href} className="hover:text-primary">{c.label}</Link>)}
            </nav>
            {ribbon && announcement && <span className="rounded-badge bg-ribbon px-3 py-0.5 text-xs text-ribbon-ink" style={{ borderRadius: 'var(--radius-badge)' }}>{announcement.url ? <Link href={link(locale, announcement.url)}>{announcement.text}</Link> : announcement.text}</span>}
          </div>
        </div>
      )}
      {announcement && (
        <div className="bg-ribbon text-center text-xs text-ribbon-ink lg:hidden"><p className="container-x py-1.5">{announcement.text}</p></div>
      )}
    </header>
  )
}
