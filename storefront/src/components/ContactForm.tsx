'use client'

import { useActionState, useState } from 'react'

import { sendContact, type ContactState } from '@/app/actions/contact'
import type { Locale } from '@/lib/config'
import type { ContactConfig } from '@/lib/data'
import { messages } from '@/lib/i18n'

import { Turnstile } from './Turnstile'

/**
 * Contact form with layered spam defences: Cloudflare Turnstile (when the shop has keys), a honeypot field no person sees, and the time the form
 * was shown. All of it is verified again on the server; nothing here is trusted.
 */
export function ContactForm({ locale, config, defaults }: { locale: Locale; config: ContactConfig; defaults: { name: string; email: string } }) {
  const t = messages(locale)
  const [state, action, pending] = useActionState<ContactState, FormData>(sendContact, {})
  const [token, setToken] = useState('')
  const [spent, setSpent] = useState('') // the token of the last submission: single-use, so useless for another one
  const [widgetError, setWidgetError] = useState(false)
  const [topic, setTopic] = useState(state.values?.topic || 'general')
  const [shownAt] = useState(() => String(Date.now()))
  const key = config.turnstileSiteKey

  if (state.ok) {
    return (
      <div role="status" className="card p-6 text-center">
        <h2 className="text-2xl">{t.sentTitle}</h2>
        <p className="mt-2">{t.sentText}</p>
        <p className="mt-2 text-sm">{t.sentRef}: <strong>{state.reference}</strong></p>
        <a href="" className="btn btn-ghost mt-4">{t.writeAnother}</a>
      </div>
    )
  }

  const v = state.values ?? {}
  const err = (f: string) => state.fields?.[f]?.[0] ?? state.fields?.[`input.${f}`]?.[0]
  const field = (name: string, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <label className="block text-sm">{label}
      <input name={name} defaultValue={v[name] ?? ''} aria-invalid={Boolean(err(name))} aria-describedby={err(name) ? `${name}-err` : undefined} className="field mt-1" {...props} />
      {err(name) && <span id={`${name}-err`} role="alert" className="text-xs text-danger">{err(name)}</span>}
    </label>
  )
  const blocked = Boolean(key) && (token === '' || token === spent)

  return (
    <form action={action} onSubmit={() => setSpent(token)} className="card space-y-4 p-6" noValidate>
      <input type="hidden" name="locale" value={locale} />
      <input type="hidden" name="renderedAt" value={shownAt} />
      <input type="hidden" name="cf-turnstile-response" value={token} />
      {/* Honeypot: invisible and unreachable for people (off-screen, no tab stop, no autofill); scripts that fill every field give themselves away. */}
      <div aria-hidden="true" style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, overflow: 'hidden' }}>
        <label>Website<input name="website" tabIndex={-1} autoComplete="off" defaultValue="" /></label>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        {field('name', t.yourName, { required: true, autoComplete: 'name', defaultValue: v.name ?? defaults.name })}
        {field('email', t.email, { type: 'email', required: true, autoComplete: 'email', defaultValue: v.email ?? defaults.email })}
        {field('phone', t.phone, { type: 'tel', autoComplete: 'tel' })}
        <label className="block text-sm">{t.topic}
          <select name="topic" className="field mt-1" value={topic} onChange={(e) => setTopic(e.target.value)}>
            {config.topics.map((k) => <option key={k} value={k}>{t.topics[k as keyof typeof t.topics] ?? k}</option>)}
          </select>
        </label>
      </div>
      {topic === 'order' && field('orderNumber', t.orderRef)}
      <label className="block text-sm">{t.yourMessage}
        <textarea name="message" required rows={7} maxLength={4000} defaultValue={v.message ?? ''} aria-invalid={Boolean(err('message'))} className="field mt-1 h-auto py-3" style={{ borderRadius: 'var(--radius-card)' }} />
        {err('message') && <span role="alert" className="text-xs text-danger">{err('message')}</span>}
      </label>
      {key && <Turnstile siteKey={key} action={config.turnstileAction} locale={locale} resetKey={state} onToken={setToken} onError={() => setWidgetError(true)} />}
      {widgetError && <p role="alert" className="text-sm text-danger">{t.humanFailed}</p>}
      {state.error === 'human' && <p role="alert" className="text-sm text-danger">{t.humanFailed}</p>}
      {state.error === 'rate' && <p role="alert" className="text-sm text-danger">{t.rateLimited}</p>}
      {state.error === 'unexpected' && <p role="alert" className="text-sm text-danger">{t.error}</p>}
      <p className="text-xs">{t.privacyNote}</p>
      <div className="flex flex-wrap items-center gap-3">
        <button className="btn btn-primary" disabled={pending || blocked}>{pending ? t.sending : t.send}</button>
        {blocked && !widgetError && <span className="text-xs">{t.humanCheck}</span>}
      </div>
    </form>
  )
}
