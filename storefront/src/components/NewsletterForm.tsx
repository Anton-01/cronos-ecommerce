'use client'

import { useActionState, useState } from 'react'

import { subscribeNewsletter, type NewsletterState } from '@/app/actions/newsletter'
import type { Locale } from '@/lib/config'
import type { NewsletterConfig } from '@/lib/data'
import { messages } from '@/lib/i18n'

import { Turnstile } from './Turnstile'

/** Footer sign-up (double opt-in). Cloudflare's script is only loaded when the person clicks into the field. */
export function NewsletterForm({ locale, config }: { locale: Locale; config: NewsletterConfig }) {
  const t = messages(locale)
  const [state, action, pending] = useActionState<NewsletterState, FormData>(subscribeNewsletter, {})
  const [armed, setArmed] = useState(false)
  const [token, setToken] = useState('')
  const [spent, setSpent] = useState('')
  const [widgetError, setWidgetError] = useState(false)
  const [shownAt] = useState(() => String(Date.now()))
  const key = config.turnstileSiteKey
  const blocked = Boolean(key) && (token === '' || token === spent)
  const message = state.status === 'check_email' ? t.newsletterCheck : state.error === 'email' ? t.error : state.error === 'human' || widgetError ? t.humanFailed : state.error === 'rate' ? t.rateLimited : state.error ? t.error : null

  return (
    <section aria-labelledby="nl-title" className="space-y-2">
      <h2 id="nl-title" className="text-base" style={{ color: 'var(--c-footer-heading)' }}>{t.newsletterTitle}</h2>
      <p className="text-sm">{t.newsletterText}</p>
      {state.status === 'check_email' ? (
        <p role="status" className="text-sm font-medium" style={{ color: 'var(--c-footer-heading)' }}>{t.newsletterCheck}</p>
      ) : (
        <form action={action} onSubmit={() => setSpent(token)} className="space-y-2">
          <input type="hidden" name="locale" value={locale} />
          <input type="hidden" name="renderedAt" value={shownAt} />
          <input type="hidden" name="cf-turnstile-response" value={token} />
          <div aria-hidden="true" style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, overflow: 'hidden' }}><label>Website<input name="website" tabIndex={-1} autoComplete="off" defaultValue="" /></label></div>
          <div className="flex gap-2">
            <label className="sr-only" htmlFor="nl-email">{t.email}</label>
            <input id="nl-email" name="email" type="email" required autoComplete="email" placeholder={t.email} onFocus={() => setArmed(true)} className="field" style={{ color: '#242424' }} />
            <button className="btn btn-primary shrink-0" disabled={pending || blocked}>{pending ? t.newsletterSending : t.newsletterButton}</button>
          </div>
          {key && armed && <Turnstile siteKey={key} action={config.turnstileAction} locale={locale} resetKey={state} onToken={setToken} onError={() => setWidgetError(true)} />}
          {key && armed && blocked && !widgetError && <p className="text-xs">{t.newsletterHuman}</p>}
          {message && <p role="alert" className="text-xs">{message}</p>}
          <p className="text-xs opacity-80">{t.newsletterConsent}</p>
        </form>
      )}
    </section>
  )
}
