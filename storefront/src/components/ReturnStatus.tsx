'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'

import { confirmCheckout } from '@/app/actions/checkout'
import { href, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

/** The provider sent the shopper back: ask the API to settle the payment (repeating is safe), a few times, because some confirmations take seconds. */
export function ReturnStatus({ checkoutId, locale }: { checkoutId: string; locale: Locale }) {
  const t = messages(locale)
  const [state, setState] = useState<'checking' | 'paid' | 'pending' | 'failed'>('checking')

  useEffect(() => {
    let alive = true
    ;(async () => {
      for (let attempt = 0; attempt < 6 && alive; attempt++) {
        const r = await confirmCheckout(checkoutId)
        if (!alive) return
        if (r.ok && (r.data?.paidAt || r.data?.status === 'paid')) return setState('paid')
        if (!r.ok || ['abandoned', 'expired', 'cancelled'].includes(r.data?.status ?? '')) return setState('failed')
        await new Promise((res) => setTimeout(res, 2000))
      }
      if (alive) setState('pending')
    })()
    return () => { alive = false }
  }, [checkoutId])

  return (
    <div className="container-x mx-auto my-16 max-w-lg text-center" role="status" aria-live="polite">
      {state === 'checking' && <p className="text-xl">{t.checkingPayment}</p>}
      {state === 'paid' && (<><h1 className="text-3xl">{t.thanks}</h1><p className="mt-2">{t.thanksText}</p><Link href={href.home(locale)} className="btn btn-primary mt-6">{t.returnToShop}</Link></>)}
      {state === 'pending' && <><p className="text-xl">{t.paymentPending}</p><Link href={href.page(locale, 'orders/track')} className="btn btn-dark mt-6">{t.trackOrder}</Link></>}
      {state === 'failed' && <><p className="text-xl text-danger">{t.paymentFailed2}</p><Link href={href.page(locale, 'checkout')} className="btn btn-primary mt-6">{t.retry}</Link></>}
    </div>
  )
}
