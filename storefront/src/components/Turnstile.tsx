'use client'

import { useEffect, useRef } from 'react'

import type { Locale } from '@/lib/config'

interface TurnstileApi {
  render(el: HTMLElement, o: { sitekey: string; action: string; theme: 'auto'; language: string; callback: (t: string) => void; 'expired-callback': () => void; 'error-callback': () => void }): string
  reset(id?: string): void
  remove(id?: string): void
}
declare global { interface Window { turnstile?: TurnstileApi } }

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit'

function loadTurnstile(): Promise<TurnstileApi> {
  return new Promise((resolve, reject) => {
    if (window.turnstile) return resolve(window.turnstile)
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${SCRIPT}"]`)
    const script = existing ?? Object.assign(document.createElement('script'), { src: SCRIPT, async: true, defer: true })
    script.addEventListener('load', () => (window.turnstile ? resolve(window.turnstile) : reject(new Error('turnstile'))), { once: true })
    script.addEventListener('error', () => reject(new Error('turnstile')), { once: true })
    existing ?? document.head.appendChild(script)
  })
}

/**
 * Cloudflare Turnstile widget. The token it produces is single-use: change `resetKey` (for instance to the last server answer) to ask for a fresh one.
 * `enabled` lets a form load Cloudflare's script only when the person starts using it, instead of on every page view.
 */
export function Turnstile({ siteKey, action, locale, enabled = true, resetKey, onToken, onError }: { siteKey: string; action: string; locale: Locale; enabled?: boolean; resetKey?: unknown; onToken: (token: string) => void; onError: () => void }) {
  const host = useRef<HTMLDivElement>(null)
  const widget = useRef<string | null>(null)
  const callbacks = useRef({ onToken, onError })
  useEffect(() => {
    callbacks.current = { onToken, onError }
  })

  useEffect(() => {
    if (!enabled || !host.current) return
    let cancelled = false
    loadTurnstile()
      .then((ts) => {
        if (cancelled || !host.current || widget.current) return
        widget.current = ts.render(host.current, {
          sitekey: siteKey, action, theme: 'auto', language: locale === 'es-MX' ? 'es' : 'en',
          callback: (t) => callbacks.current.onToken(t), 'expired-callback': () => callbacks.current.onToken(''), 'error-callback': () => { callbacks.current.onToken(''); callbacks.current.onError() },
        })
      })
      .catch(() => callbacks.current.onError())
    return () => { cancelled = true; if (widget.current) window.turnstile?.remove(widget.current); widget.current = null }
  }, [enabled, siteKey, action, locale])

  useEffect(() => {
    if (widget.current) window.turnstile?.reset(widget.current)
  }, [resetKey])

  return <div ref={host} />
}
