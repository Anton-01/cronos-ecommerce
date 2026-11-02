import { notFound } from 'next/navigation'

import { categoryMetadata, CategoryView } from '@/components/CategoryPage'
import { LOCALES, localeFromPrefix } from '@/lib/config'

type Props = { params: Promise<{ locale: string; slug: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> }

/** The segment belongs to one language only (see seoRoutes): the other language's word is a 404, not a duplicate page. */
const ok = (locale: string) => {
  const l = localeFromPrefix(locale)
  return l !== null && LOCALES[l].category === 'categorias'
}

export async function generateMetadata({ params, searchParams }: Props) {
  const { locale, slug } = await params
  return ok(locale) ? categoryMetadata(locale, slug, await searchParams) : {}
}

export default async function Page({ params, searchParams }: Props) {
  const { locale, slug } = await params
  if (!ok(locale)) notFound()
  return <CategoryView rawLocale={locale} slug={slug} searchParams={await searchParams} />
}
