import { notFound } from 'next/navigation'

import { pageMetadata, StaticPageView } from '@/components/StaticPageView'
import { LOCALES, localeFromPrefix } from '@/lib/config'

type Props = { params: Promise<{ locale: string; slug: string }> }

/** The segment belongs to one language only (see seoRoutes). */
const ok = (locale: string) => {
  const l = localeFromPrefix(locale)
  return l !== null && LOCALES[l].page === 'informacion'
}

export async function generateMetadata({ params }: Props) {
  const { locale, slug } = await params
  return ok(locale) ? pageMetadata(locale, slug) : {}
}

export default async function Page({ params }: Props) {
  const { locale, slug } = await params
  if (!ok(locale)) notFound()
  return <StaticPageView rawLocale={locale} slug={slug} />
}
