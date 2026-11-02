import type { Metadata } from 'next'
import { notFound } from 'next/navigation'

import { ReturnStatus } from '@/components/ReturnStatus'
import { resolveLocale } from '@/lib/context'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function ReturnPage({ params, searchParams }: { params: Promise<{ locale: string }>; searchParams: Promise<{ checkout?: string }> }) {
  const locale = resolveLocale((await params).locale)
  const { checkout } = await searchParams
  if (!checkout || !/^[A-Za-z0-9-]{6,64}$/.test(checkout)) notFound()
  return <ReturnStatus checkoutId={checkout} locale={locale} />
}
