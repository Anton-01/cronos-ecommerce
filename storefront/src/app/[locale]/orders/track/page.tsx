import type { Metadata } from 'next'

import { OrderView, ORDER_FIELDS, type OrderData } from '@/components/OrderView'
import { resolveLocale } from '@/lib/context'
import { gql } from '@/lib/gql'
import { messages } from '@/lib/i18n'

export const metadata: Metadata = { robots: { index: false, follow: false } }

/** Guest tracking: number + the email used to buy. A wrong pair answers the same as an unknown order (nothing is revealed). */
export default async function TrackPage({ params, searchParams }: { params: Promise<{ locale: string }>; searchParams: Promise<{ number?: string; email?: string }> }) {
  const locale = resolveLocale((await params).locale)
  const { number, email } = await searchParams
  const t = messages(locale)
  const order = number && email ? (await gql<{ trackOrder: OrderData | null }>(`query($n: String!, $e: String!) { trackOrder(number: $n, email: $e) { ${ORDER_FIELDS} } }`, { n: number.trim(), e: email.trim() }).catch(() => ({ trackOrder: null }))).trackOrder : null
  return (
    <>
      <div className="container-x mx-auto mt-8 max-w-md">
        <form method="get" className="card space-y-4 p-6">
          <h1 className="text-3xl">{t.trackTitle}</h1>
          <p className="text-sm">{t.trackText}</p>
          <label className="block text-sm">{t.orderNumber}<input name="number" required defaultValue={number} className="field mt-1" /></label>
          <label className="block text-sm">{t.email}<input name="email" type="email" required defaultValue={email} className="field mt-1" /></label>
          <button className="btn btn-primary w-full">{t.track}</button>
          {number && email && !order && <p role="alert" className="text-sm text-danger">{t.trackNotFound}</p>}
        </form>
      </div>
      {order && <OrderView order={order} locale={locale} />}
    </>
  )
}
