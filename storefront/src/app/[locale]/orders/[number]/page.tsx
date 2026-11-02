import type { Metadata } from 'next'
import { notFound } from 'next/navigation'

import { OrderView, ORDER_FIELDS, type OrderData } from '@/components/OrderView'
import { href } from '@/lib/config'
import { resolveLocale } from '@/lib/context'
import { gql } from '@/lib/gql'
import { requireUser } from '@/lib/user'

export const metadata: Metadata = { robots: { index: false, follow: false } }

export default async function OrderPage({ params }: { params: Promise<{ locale: string; number: string }> }) {
  const { locale: raw, number } = await params
  const locale = resolveLocale(raw)
  await requireUser(locale, href.page(locale, `orders/${number}`))
  const { myOrder } = await gql<{ myOrder: OrderData | null }>(`query($n: String!) { myOrder(number: $n) { ${ORDER_FIELDS} } }`, { n: number })
  if (!myOrder) notFound()
  return <OrderView order={myOrder} locale={locale} />
}
