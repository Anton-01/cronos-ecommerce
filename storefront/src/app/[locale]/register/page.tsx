import type { Metadata } from 'next'
import { redirect } from 'next/navigation'

import { AuthForm } from '@/components/AuthForm'
import { href } from '@/lib/config'
import { resolveLocale } from '@/lib/context'
import { readToken } from '@/lib/session'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function Page({ params, searchParams }: { params: Promise<{ locale: string }>; searchParams: Promise<{ next?: string }> }) {
  const locale = resolveLocale((await params).locale)
  if (await readToken()) redirect(href.page(locale, 'account'))
  return <AuthForm mode="register" locale={locale} next={(await searchParams).next} />
}
