'use client'

import { usePathname, useRouter } from 'next/navigation'
import { useEffect, useRef, useState, useTransition } from 'react'

import { chooseMarket } from '@/app/actions/cart'
import { LOCALES, type Locale } from '@/lib/config'

interface MarketOption { code: string; name: string }

/** Store and language pickers. Changing the language swaps the URL prefix; changing the store remembers it and refreshes prices. */
export function MarketSwitcher({ markets, current, locale, labels }: { markets: MarketOption[]; current: string; locale: Locale; labels: { store: string; language: string } }) {
  const router = useRouter()
  const pathname = usePathname()
  const [pending, start] = useTransition()

  const switchLocale = (next: Locale) => {
    const parts = pathname.split('/')
    parts[1] = LOCALES[next].prefix
    // Slugs differ per language: land on the language's home instead of a link that may not exist.
    router.push(parts.length > 2 && ['cart', 'checkout', 'account', 'orders', 'login', 'register', 'wishlist', 'search'].includes(parts[2]!) ? parts.join('/') : `/${LOCALES[next].prefix}`)
  }

  return (
    <div className="flex items-center gap-3 text-xs" aria-busy={pending}>
      {markets.length > 1 && (
        <label className="flex items-center gap-1">
          <span className="sr-only">{labels.store}</span>
          <select className="bg-transparent text-topbar-ink" value={current} onChange={(e) => start(async () => { await chooseMarket(e.target.value); router.refresh() })}>
            {markets.map((m) => <option key={m.code} value={m.code}>{m.name}</option>)}
          </select>
        </label>
      )}
      <label className="flex items-center gap-1">
        <span className="sr-only">{labels.language}</span>
        <select className="bg-transparent text-topbar-ink" value={locale} onChange={(e) => switchLocale(e.target.value as Locale)}>
          {(Object.keys(LOCALES) as Locale[]).map((l) => <option key={l} value={l}>{LOCALES[l].label}</option>)}
        </select>
      </label>
    </div>
  )
}

/** "You are browsing another store" notice (Amazon style). The text and the decision come from the API; staying is remembered for good. */
export function MarketPrompt({ prompt, detected, selected }: { prompt: { title: string; body: string; switchLabel: string; stayLabel: string }; detected: string; selected: string }) {
  const router = useRouter()
  const [open, setOpen] = useState(true)
  const [pending, start] = useTransition()
  const dialog = useRef<HTMLDialogElement>(null)

  useEffect(() => {
    if (open && dialog.current && !dialog.current.open) dialog.current.showModal()
  }, [open])
  if (!open) return null

  const decide = (code: string) => start(async () => { await chooseMarket(code); setOpen(false); router.refresh() })

  return (
    <dialog ref={dialog} aria-labelledby="market-prompt-title" onCancel={() => decide(selected)} className="card m-auto w-[min(92vw,28rem)] p-6 backdrop:bg-black/50">
      <h2 id="market-prompt-title" className="text-xl">{prompt.title}</h2>
      <p className="mt-2 text-sm">{prompt.body}</p>
      <div className="mt-5 flex flex-wrap gap-2">
        <button type="button" className="btn btn-primary" disabled={pending} onClick={() => decide(detected)}>{prompt.switchLabel}</button>
        <button type="button" className="btn btn-ghost" disabled={pending} onClick={() => decide(selected)}>{prompt.stayLabel}</button>
      </div>
    </dialog>
  )
}
