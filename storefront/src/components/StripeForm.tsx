'use client'

import { useEffect, useRef, useState } from 'react'

import type { Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

/* Minimal typing of what is used from Stripe.js (loaded from Stripe, never bundled). */
interface StripeLike {
  elements(o: { clientSecret: string }): { create(type: 'payment'): { mount(el: HTMLElement): void; unmount(): void } }
  confirmPayment(o: { elements: unknown; confirmParams: { return_url: string } }): Promise<{ error?: { message?: string } }>
}
declare global { interface Window { Stripe?: (key: string) => StripeLike } }

function loadStripe(): Promise<void> {
  if (window.Stripe) return Promise.resolve()
  return new Promise((resolve, reject) => {
    const s = document.createElement('script')
    s.src = 'https://js.stripe.com/v3/'
    s.async = true
    s.onload = () => resolve()
    s.onerror = () => reject(new Error('stripe'))
    document.head.appendChild(s)
  })
}

/** Stripe's own Payment Element: card data goes from the browser to Stripe, never through this site or the API (PCI scope stays with Stripe). */
export function StripeForm({ clientSecret, publishableKey, returnUrl, locale }: { clientSecret: string; publishableKey: string | null; returnUrl: string; locale: Locale }) {
  const t = messages(locale)
  const host = useRef<HTMLDivElement>(null)
  const ctx = useRef<{ stripe: StripeLike; elements: ReturnType<StripeLike['elements']> } | null>(null)
  const [failure, setError] = useState<string | null>(null)
  const error = publishableKey ? failure : t.paymentFailed
  const [busy, setBusy] = useState(false)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    let element: { unmount(): void } | null = null
    let cancelled = false
    if (!publishableKey) return
    loadStripe()
      .then(() => {
        if (cancelled || !host.current || !window.Stripe) return
        const stripe = window.Stripe(publishableKey)
        const elements = stripe.elements({ clientSecret })
        const payment = elements.create('payment')
        payment.mount(host.current)
        element = payment
        ctx.current = { stripe, elements }
        setReady(true)
      })
      .catch(() => setError(t.paymentFailed))
    return () => { cancelled = true; element?.unmount() }
  }, [clientSecret, publishableKey, t.paymentFailed])

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!ctx.current) return
    setBusy(true)
    const r = await ctx.current.stripe.confirmPayment({ elements: ctx.current.elements, confirmParams: { return_url: returnUrl } })
    if (r.error) { setError(r.error.message ?? t.paymentFailed); setBusy(false) }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <div ref={host} />
      {error && <p role="alert" className="text-sm text-danger">{error}</p>}
      <button className="btn btn-primary" disabled={!ready || busy}>{t.payNow}</button>
    </form>
  )
}
