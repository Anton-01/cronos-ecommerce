import Link from 'next/link'

import type { Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'
import type { Market, Theme } from '@/lib/types'

import { href } from '@/lib/config'
import type { NewsletterConfig, PageLinkItem } from '@/lib/data'

import { Logo, link } from './Header'
import { NewsletterForm } from './NewsletterForm'

export function Footer({ theme, locale, market, pages, newsletter }: { theme: Theme; locale: Locale; market: Market; pages: PageLinkItem[]; newsletter: NewsletterConfig | null }) {
  const t = messages(locale)
  const nav = theme.navigation
  const social = Object.entries(nav.social).filter(([, url]) => url)
  return (
    <footer className="mt-16 bg-footer pb-20 text-footer-ink lg:pb-0">
      <div className="container-x grid gap-10 py-12 md:grid-cols-[1.4fr_repeat(3,1fr)]">
        <div className="space-y-3">
          <Logo theme={theme} locale={locale} dark />
          {theme.tagline && <p className="text-sm">{theme.tagline}</p>}
          {social.length > 0 && (
            <ul className="flex flex-wrap gap-3 text-sm">
              {social.map(([name, url]) => <li key={name}><a href={url} rel="noopener noreferrer" target="_blank" className="inline-block py-1 underline-offset-4 hover:underline">{name}</a></li>)}
            </ul>
          )}
          {(market.profile.contactEmail || market.profile.contactPhone) && (
            <address className="space-y-0.5 text-sm not-italic">
              {market.profile.contactEmail && <div><a className="inline-block py-1" href={`mailto:${market.profile.contactEmail}`}>{market.profile.contactEmail}</a></div>}
              {market.profile.contactPhone && <div><a className="inline-block py-1" href={`tel:${market.profile.contactPhone}`}>{market.profile.contactPhone}</a></div>}
              {market.profile.supportHours && <div>{market.profile.supportHours}</div>}
            </address>
          )}
          {newsletter && <div className="pt-3"><NewsletterForm locale={locale} config={newsletter} /></div>}
        </div>
        <nav aria-label={t.information}>
            <h2 className="mb-3 text-base" style={{ color: 'var(--c-footer-heading)' }}>{t.information}</h2>
            <ul className="space-y-1.5 text-sm">
              {pages.map((p) => <li key={p.key}><Link href={href.info(locale, p.slug)} className="inline-block py-0.5 hover:underline">{p.title}</Link></li>)}
              <li><Link href={href.page(locale, 'contact')} className="inline-block py-0.5 hover:underline">{t.contactTitle}</Link></li>
            </ul>
        </nav>
        {nav.footerColumns.map((col) => (
          <nav key={col.title} aria-label={col.title}>
            <h2 className="mb-3 text-base" style={{ color: 'var(--c-footer-heading)' }}>{col.title}</h2>
            <ul className="space-y-1.5 text-sm">
              {col.links.map((l) => <li key={l.url + l.label}><Link href={link(locale, l.url)} className="inline-block py-0.5 hover:underline">{l.label}</Link></li>)}
            </ul>
          </nav>
        ))}
      </div>
      <div className="border-t" style={{ borderColor: 'rgba(255,255,255,.12)' }}>
        <div className="container-x flex flex-wrap items-center justify-between gap-2 py-4 text-xs">
          <p>© {new Date().getFullYear()} {market.profile.tradeName ?? theme.siteName}. {t.rights}</p>
          {nav.footerNote && <p>{nav.footerNote}</p>}
        </div>
      </div>
    </footer>
  )
}
