'use client'

import Link from 'next/link'
import { useMemo, useState, useTransition } from 'react'

import { addToCart } from '@/app/actions/cart'
import { href, type Locale } from '@/lib/config'
import { clampToStep, isMultipleOf, money } from '@/lib/format'
import { fill, messages } from '@/lib/i18n'
import type { Pricing, Sales, Stock } from '@/lib/types'

import { useVariationImages } from './VariationImages'

export interface Attribute { code: string; name: string | null; values: { id: string; label: string | null; meta: { hex?: string } | null }[] }
export interface Variation { id: string; sku: string | null; stockStatus: Stock; valueIds: string[]; pricing: Pricing | null }

interface Props {
  locale: Locale
  market: string
  productId: string
  sales: Sales
  pricing: Pricing | null
  stock: Stock
  attributes: Attribute[]
  variations: Variation[]
}

/**
 * Where ribbons are sold their way: by the metre in steps (0.5 m), by the whole roll, or by the piece. The controls follow `sales` from the API;
 * the server re-checks step, minimum, maximum and stock, so what is shown here is guidance and never the authority.
 */
export function BuyBox({ locale, market, productId, sales, pricing, stock, attributes, variations }: Props) {
  const t = messages(locale)
  const isCut = sales.unit === 'METER'
  const [mode, setMode] = useState<'cut' | 'roll'>(sales.sellByCut || !sales.sellByRoll ? 'cut' : 'roll')
  const step = Number(sales.step) || 1
  const min = Number(sales.min) || step
  const max = sales.max ? Number(sales.max) : null
  const [qty, setQty] = useState<string>(String(mode === 'roll' ? 1 : min))
  const [chosen, setChosen] = useState<Record<string, string>>({})
  const photos = useVariationImages()
  const [pending, start] = useTransition()
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)

  const variable = variations.length > 0
  const variationAttrs = attributes.filter((a) => variations.some((v) => a.values.some((x) => v.valueIds.includes(x.id))))
  const match = useMemo(() => {
    if (!variable) return null
    if (variationAttrs.some((a) => !chosen[a.code])) return null
    const ids = Object.values(chosen)
    return variations.find((v) => ids.every((id) => v.valueIds.includes(id))) ?? null
  }, [variable, variationAttrs, chosen, variations])

  const active = variable ? match : { id: productId, pricing, stockStatus: stock }
  const activePricing = active?.pricing ?? (variable ? null : pricing)
  const byRoll = mode === 'roll'
  const n = Number(qty)
  const wholeRolls = byRoll
  const stepNow = wholeRolls ? 1 : step
  const minNow = wholeRolls ? 1 : min
  const valid = Number.isFinite(n) && n >= minNow && (max === null || wholeRolls || n <= max) && isMultipleOf(n, stepNow)
  const unitPrice = activePricing ? Number(byRoll ? (activePricing.rollPrice?.amount ?? NaN) : activePricing.price.amount) : NaN
  const estimate = Number.isFinite(unitPrice) && valid ? unitPrice * n : null
  const out = (active?.stockStatus ?? stock) === 'OUT_OF_STOCK'
  const canBuy = !out && active !== null && valid && (!byRoll || activePricing?.rollPrice != null)

  /** Picks a value; if that completes a combination, the gallery switches to that variation's photos. */
  const choose = (code: string, valueId: string) => {
    const next = { ...chosen, [code]: valueId }
    setChosen(next)
    const done = variationAttrs.every((a) => next[a.code])
    photos?.select(done ? (variations.find((v) => Object.values(next).every((id) => v.valueIds.includes(id)))?.id ?? null) : null)
  }

  const setMode2 = (next: 'cut' | 'roll') => {
    setMode(next)
    setQty(next === 'roll' ? '1' : String(min))
    setNotice(null)
  }
  const bump = (dir: 1 | -1) => setQty(String(clampToStep((Number.isFinite(n) ? n : 0) + dir * stepNow, stepNow, minNow, wholeRolls ? null : max)))

  const submit = () => {
    if (!active || !canBuy) return
    setNotice(null)
    start(async () => {
      const r = await addToCart(active.id, qty, byRoll, market)
      setNotice(r.ok ? { ok: true, text: r.replaced ? `${t.added}. ${t.replacedMode}` : t.added } : { ok: false, text: r.message ?? t.error })
    })
  }

  return (
    <div className="space-y-4">
      {variationAttrs.map((a) => (
        <fieldset key={a.code}>
          <legend className="mb-2 text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{a.name}</legend>
          <div className="flex flex-wrap gap-2">
            {a.values.filter((v) => variations.some((x) => x.valueIds.includes(v.id))).map((v) => {
              const on = chosen[a.code] === v.id
              return (
                <button key={v.id} type="button" aria-pressed={on} title={v.label ?? ''} onClick={() => choose(a.code, v.id)} className="min-h-9 min-w-9 border px-3 text-sm" style={{ borderRadius: 'var(--radius-button)', borderColor: on ? 'var(--c-primary)' : 'var(--c-border)', background: v.meta?.hex ? v.meta.hex : 'var(--c-surface)', outline: on ? '2px solid var(--c-primary)' : undefined, outlineOffset: 2, color: v.meta?.hex ? 'transparent' : undefined, width: v.meta?.hex ? 36 : undefined, padding: v.meta?.hex ? 0 : undefined }}>
                  {v.meta?.hex ? <span className="sr-only">{v.label}</span> : v.label}
                </button>
              )
            })}
          </div>
        </fieldset>
      ))}

      {isCut && sales.sellByCut && sales.sellByRoll && (
        <fieldset>
          <legend className="mb-2 text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{t.buyBy}</legend>
          <div className="inline-flex overflow-hidden border" style={{ borderRadius: 'var(--radius-button)', borderColor: 'var(--c-border)' }}>
            <button type="button" aria-pressed={mode === 'cut'} onClick={() => setMode2('cut')} className={`px-4 py-2 text-sm ${mode === 'cut' ? 'bg-secondary text-on-secondary' : 'bg-surface'}`}>{t.cut}</button>
            <button type="button" aria-pressed={mode === 'roll'} onClick={() => setMode2('roll')} className={`px-4 py-2 text-sm ${mode === 'roll' ? 'bg-secondary text-on-secondary' : 'bg-surface'}`}>{t.roll}{sales.rollLength ? ` (${fill(t.rollOf, { n: Number(sales.rollLength) })})` : ''}</button>
          </div>
        </fieldset>
      )}

      <div>
        <label htmlFor="qty" className="mb-1 block text-sm font-semibold" style={{ color: 'var(--c-heading)' }}>{byRoll ? t.roll : isCut ? t.length : t.quantity}</label>
        <div className="flex items-center gap-2">
          <div className="inline-flex items-center border bg-surface" style={{ borderRadius: 'var(--radius-button)', borderColor: 'var(--c-border)' }}>
            <button type="button" aria-label="−" className="size-10" onClick={() => bump(-1)}>−</button>
            <input id="qty" inputMode="decimal" value={qty} onChange={(e) => setQty(e.target.value.replace(',', '.'))} aria-invalid={!valid} aria-describedby="qty-hint" className="h-10 w-20 bg-transparent text-center" />
            <button type="button" aria-label="+" className="size-10" onClick={() => bump(1)}>+</button>
          </div>
          {isCut && !byRoll && <span className="text-sm">{t.metres}</span>}
        </div>
        <p id="qty-hint" className={`mt-1 text-xs ${valid ? '' : 'text-danger'}`}>{isCut && !byRoll ? fill(t.stepHint, { step, min }) : fill(t.minHint, { min: minNow })}</p>
      </div>

      {estimate !== null && activePricing && <p className="text-sm">{t.lineTotal}: <strong style={{ color: 'var(--c-heading)' }}>{money(estimate, activePricing.currency, locale)}</strong> <span className="text-xs">{activePricing.taxIncluded ? t.taxIncluded : t.plusTax}</span></p>}
      {variable && !match && <p className="text-sm">{variationAttrs.map((a) => a.name).join(' / ')}</p>}

      <div className="flex flex-wrap items-center gap-3">
        <button type="button" className="btn btn-primary" onClick={submit} disabled={!canBuy || pending}>{pending ? t.adding : out ? t.outOfStock : t.addToCart}</button>
        {notice?.ok && <Link href={href.page(locale, 'cart')} className="btn btn-dark">{t.viewCart}</Link>}
      </div>
      <p role="status" aria-live="polite" className={`min-h-5 text-sm ${notice && !notice.ok ? 'text-danger' : ''}`}>{notice?.text}</p>
    </div>
  )
}
