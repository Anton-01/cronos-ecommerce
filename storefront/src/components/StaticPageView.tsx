import type { Metadata } from 'next'
import Link from 'next/link'
import { notFound } from 'next/navigation'

import { href, LOCALES, SITE_URL, type Locale } from '@/lib/config'
import { getMarket, resolveLocale } from '@/lib/context'
import { getPage } from '@/lib/data'
import { messages } from '@/lib/i18n'

export async function pageMetadata(rawLocale: string, slug: string): Promise<Metadata> {
  const locale = resolveLocale(rawLocale)
  const { market } = await getMarket()
  const page = await getPage(locale, slug, market.code)
  if (!page) return {}
  return {
    title: page.seoTitle,
    description: page.seoDescription ?? undefined,
    alternates: {
      canonical: `${SITE_URL}${href.info(locale, page.slug)}`,
      languages: Object.fromEntries(page.alternates.map((a) => [a.locale, `${SITE_URL}${href.info(a.locale as Locale, a.slug)}`])),
    },
  }
}

/** A static page. Its text arrives as safe HTML made by the API when the page was saved; the data is cached, so this rarely reaches the API. */
export async function StaticPageView({ rawLocale, slug }: { rawLocale: string; slug: string }) {
  const locale = resolveLocale(rawLocale)
  const { market } = await getMarket()
  const page = await getPage(locale, slug, market.code)
  if (!page) notFound()
  const t = messages(locale)
  return (
    <article className="container-x mx-auto mt-6 max-w-3xl">
      <nav aria-label="breadcrumb" className="text-sm">
        <ol className="flex flex-wrap items-center gap-2">
          <li><Link href={href.home(locale)} className="hover:text-primary">{t.home}</Link></li>
          <li className="flex gap-2"><span aria-hidden>/</span><span aria-current="page">{page.title}</span></li>
        </ol>
      </nav>
      <h1 className="mt-3 text-4xl md:text-5xl">{page.title}</h1>
      {/* Safe by construction: the API drops raw HTML and unsafe links when it renders the Markdown. */}
      <div className="page-body card mt-6 p-6 md:p-8" dangerouslySetInnerHTML={{ __html: page.bodyHtml }} />
      {page.updatedAt && <p className="mt-3 text-xs">{new Intl.DateTimeFormat(locale, { dateStyle: 'long' }).format(new Date(page.updatedAt))}</p>}
    </article>
  )
}

export const pageSegments = Object.fromEntries(Object.entries(LOCALES).map(([l, v]) => [l, v.page]))
