import type { Metadata } from 'next'
import { notFound } from 'next/navigation'

import { NewsletterTokenPage } from '@/components/NewsletterTokenPage'
import { resolveLocale } from '@/lib/context'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function Page({ params, searchParams }: { params: Promise<{ locale: string }>; searchParams: Promise<{ token?: string }> }) {
  const locale = resolveLocale((await params).locale)
  const { token } = await searchParams
  if (!token || token.length > 400) notFound()
  return <NewsletterTokenPage mode="unsubscribe" token={token} locale={locale} />
}
