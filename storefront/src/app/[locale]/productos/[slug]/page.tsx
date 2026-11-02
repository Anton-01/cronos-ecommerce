import { notFound } from 'next/navigation'

import { productMetadata, ProductView } from '@/components/ProductPage'
import { LOCALES, localeFromPrefix } from '@/lib/config'

type Props = { params: Promise<{ locale: string; slug: string }> }

const ok = (locale: string) => {
  const l = localeFromPrefix(locale)
  return l !== null && LOCALES[l].product === 'productos'
}

export async function generateMetadata({ params }: Props) {
  const { locale, slug } = await params
  return ok(locale) ? productMetadata(locale, slug) : {}
}

export default async function Page({ params }: Props) {
  const { locale, slug } = await params
  if (!ok(locale)) notFound()
  return <ProductView rawLocale={locale} slug={slug} />
}
