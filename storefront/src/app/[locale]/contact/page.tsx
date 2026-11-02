import type { Metadata } from 'next'
import Link from 'next/link'

import { ContactForm } from '@/components/ContactForm'
import { href, SITE_URL } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { getContactConfig } from '@/lib/data'
import { messages } from '@/lib/i18n'
import { getMe } from '@/lib/user'

export async function generateMetadata({ params }: { params: Promise<{ locale: string }> }): Promise<Metadata> {
  const locale = resolveLocale((await params).locale)
  const t = messages(locale)
  return { title: t.contactTitle, description: t.contactIntro, alternates: { canonical: `${SITE_URL}${href.page(locale, 'contact')}` } }
}

export default async function ContactPage({ params }: { params: Promise<{ locale: string }> }) {
  const locale = resolveLocale((await params).locale)
  const t = messages(locale)
  const [{ market }, config, me] = await Promise.all([getMarket(), getContactConfig(), getMe()])
  const p = market.profile
  return (
    <div className="container-x mt-6">
      <nav aria-label="breadcrumb" className="text-sm"><Link href={href.home(locale)} className="hover:text-primary">{t.home}</Link> / <span aria-current="page">{t.contactTitle}</span></nav>
      <h1 className="mt-3 text-4xl md:text-5xl">{t.contactTitle}</h1>
      <p className="mt-2 max-w-2xl">{t.contactIntro}</p>
      <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_320px]">
        <ContactForm locale={locale} config={config} defaults={{ name: [me?.profile?.firstName, me?.profile?.lastName].filter(Boolean).join(' '), email: me?.email ?? '' }} />
        {(p.contactEmail || p.contactPhone || p.supportHours) && (
          <aside className="card h-fit space-y-2 p-5 text-sm" aria-label={t.contactData}>
            <h2 className="text-xl">{t.contactData}</h2>
            {p.contactEmail && <p><a className="underline" href={`mailto:${p.contactEmail}`}>{p.contactEmail}</a></p>}
            {p.contactPhone && <p><a className="underline" href={`tel:${p.contactPhone}`}>{p.contactPhone}</a></p>}
            {p.supportHours && <p>{t.hours}: {p.supportHours}</p>}
          </aside>
        )}
      </div>
    </div>
  )
}
