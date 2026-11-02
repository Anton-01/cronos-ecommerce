import type { Metadata } from 'next'
import Link from 'next/link'

import { logout } from '@/app/actions/auth'
import { href } from '@/lib/config'
import { resolveLocale } from '@/lib/context'
import { money } from '@/lib/format'
import { gql } from '@/lib/gql'
import { messages } from '@/lib/i18n'
import { requireUser } from '@/lib/user'

export const metadata: Metadata = { robots: { index: false, follow: false } }

interface OrderRow { number: string; status: string; currency: string; total: string; placedAt: string | null }

export default async function AccountPage({ params }: { params: Promise<{ locale: string }> }) {
  const rawLocale = (await params).locale
  const locale = resolveLocale(rawLocale)
  const me = await requireUser(locale, href.page(locale, 'account'))
  const t = messages(locale)
  const { myOrders } = await gql<{ myOrders: { total: number; rows: OrderRow[] } }>(`query { myOrders(first: 20) { total rows { number status currency total placedAt } } }`)
  const name = [me.profile?.firstName, me.profile?.lastName].filter(Boolean).join(' ')
  return (
    <div className="container-x mt-6 space-y-6">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-3xl md:text-4xl">{t.hello}{name ? `, ${name}` : ''}</h1>
          <p className="text-sm">{me.email}</p>
        </div>
        <div className="flex gap-2">
          <Link href={href.page(locale, 'account/addresses')} className="btn btn-ghost">{t.addresses}</Link>
          <form action={logout.bind(null, rawLocale)}><button className="btn btn-ghost">{t.signOut}</button></form>
        </div>
      </header>
      <section className="card p-5" aria-labelledby="orders">
        <h2 id="orders" className="mb-3 text-xl">{t.myOrders}</h2>
        {myOrders.rows.length === 0 ? <p>{t.noOrders}</p> : (
          <ul className="divide-y" style={{ borderColor: 'var(--c-border)' }}>
            {myOrders.rows.map((o) => (
              <li key={o.number} className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
                <Link href={href.page(locale, `orders/${o.number}`)} className="font-semibold underline" style={{ color: 'var(--c-heading)' }}>{o.number}</Link>
                <span>{o.status}</span>
                <span>{o.placedAt ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(o.placedAt)) : ''}</span>
                <strong style={{ color: 'var(--c-heading)' }}>{money(o.total, o.currency, locale)}</strong>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  )
}
