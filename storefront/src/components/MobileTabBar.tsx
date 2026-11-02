import Link from 'next/link'

import { href, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

const svg = { width: 22, height: 22, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.8, 'aria-hidden': true } as const

/** The phone's bottom bar: Shop · Wishlist · Cart · Account, like the reference. Hidden from 1024 px up. */
export function MobileTabBar({ locale, cartCount, wishlist }: { locale: Locale; cartCount: number; wishlist: boolean }) {
  const t = messages(locale)
  const tabs = [
    { to: href.home(locale), label: t.shop, d: 'M3 10.5 12 3l9 7.5V21H3z' },
    ...(wishlist ? [{ to: href.page(locale, 'wishlist'), label: t.wishlist, d: 'M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9Z' }] : []),
    { to: href.page(locale, 'cart'), label: t.cart, d: 'M6 6h15l-2 9H8L6 6Zm0 0L5 3H2', badge: cartCount },
    { to: href.page(locale, 'account'), label: t.account, d: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-8 9a8 8 0 0 1 16 0' },
  ]
  return (
    <nav aria-label="Principal" className="fixed inset-x-0 bottom-0 z-30 border-t bg-header text-header-ink lg:hidden" style={{ borderColor: 'var(--c-border)', paddingBottom: 'env(safe-area-inset-bottom)' }}>
      <ul className="grid" style={{ gridTemplateColumns: `repeat(${tabs.length}, 1fr)` }}>
        {tabs.map((tab) => (
          <li key={tab.to}>
            <Link href={tab.to} className="relative flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px]">
              <svg {...svg}><path d={tab.d} /></svg>
              {tab.label}
              {'badge' in tab && tab.badge ? <span className="absolute right-1/4 top-1.5 grid min-w-4 place-items-center rounded-full bg-primary px-1 text-[10px] text-on-primary">{tab.badge}</span> : null}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  )
}
