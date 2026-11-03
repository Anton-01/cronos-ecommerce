'use client'

import Link from 'next/link'
import { useActionState } from 'react'

import { confirmNewsletter, unsubscribeNewsletter, type NewsletterState } from '@/app/actions/newsletter'
import { href, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

/** The page a newsletter e-mail link opens. Nothing happens until the person presses the button. */
export function NewsletterTokenPage({ mode, token, locale }: { mode: 'confirm' | 'unsubscribe'; token: string; locale: Locale }) {
  const t = messages(locale)
  const [state, action, pending] = useActionState<NewsletterState, FormData>(() => (mode === 'confirm' ? confirmNewsletter(token) : unsubscribeNewsletter(token)), {})
  const done = state.status === 'confirmed' || state.status === 'unsubscribed'
  return (
    <div className="container-x mx-auto my-14 max-w-lg text-center" role="status" aria-live="polite">
      {done ? (
        <>
          <h1 className="text-3xl">{mode === 'confirm' ? t.confirmedTitle : t.unsubscribedTitle}</h1>
          <p className="mt-2">{mode === 'confirm' ? t.confirmedText : t.unsubscribedText}</p>
          <Link href={href.home(locale)} className="btn btn-primary mt-6">{t.returnToShop}</Link>
        </>
      ) : (
        <form action={action} className="card space-y-4 p-6">
          <h1 className="text-3xl">{mode === 'confirm' ? t.newsletterTitle : t.unsubscribeTitle}</h1>
          {mode === 'unsubscribe' && <p>{t.unsubscribeText}</p>}
          {state.error && <p role="alert" className="text-sm text-danger">{state.error === 'expired' ? t.linkExpired : state.error === 'invalid' ? t.linkInvalid : t.error}</p>}
          <button className="btn btn-primary" disabled={pending}>{mode === 'confirm' ? t.confirmSubscription : t.unsubscribeButton}</button>
        </form>
      )}
    </div>
  )
}
