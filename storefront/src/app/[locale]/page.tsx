import type { Metadata } from 'next'

import { HomeSections } from '@/components/home/HomeSections'
import { getMarket, previewToken, resolveLocale } from '@/lib/context'
import { getCategoryTree, getTheme } from '@/lib/data'
import { gql } from '@/lib/gql'
import { LOCALES } from '@/lib/config'

type Props = { params: Promise<{ locale: string }> }

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const locale = resolveLocale((await params).locale)
  const { market } = await getMarket()
  const seo = await gql<{ seo: { title: string | null; description: string | null; canonicalUrl?: string | null } | null }>(
    `query($locale: String!, $market: String) { seo(target: HOME, locale: $locale, market: $market) { title description } }`,
    { locale, market: market.code },
    { revalidate: 300 },
  ).catch(() => ({ seo: null }))
  return {
    title: seo.seo?.title ? { absolute: seo.seo.title } : undefined,
    description: seo.seo?.description ?? undefined,
    alternates: { languages: Object.fromEntries(Object.entries(LOCALES).map(([l, v]) => [l, `/${v.prefix}`])) },
  }
}

export default async function HomePage({ params }: Props) {
  const locale = resolveLocale((await params).locale)
  const { market } = await getMarket()
  const [theme, tree] = await Promise.all([getTheme(locale, market.code, await previewToken()), getCategoryTree(locale)])
  return (
    <>
      <h1 className="sr-only">{theme.siteName}</h1>
      <HomeSections sections={theme.home} locale={locale} market={market} tree={tree} shape={theme.tokens.shape.categoryShape} />
    </>
  )
}
