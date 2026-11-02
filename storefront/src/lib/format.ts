import type { Locale } from './config'

export function money(amount: string | number, currency: string, locale: Locale): string {
  const value = typeof amount === 'number' ? amount : Number(amount)
  return new Intl.NumberFormat(locale, { style: 'currency', currency }).format(Number.isFinite(value) ? value : 0)
}

/** "2.5" → "2.5", "3.000" → "3": quantities come from the API as decimal strings. */
export function quantity(value: string, locale: Locale): string {
  return new Intl.NumberFormat(locale, { maximumFractionDigits: 3 }).format(Number(value))
}

/** Decimal-string arithmetic for what the shopper types: the server stays the authority, this only checks the step before sending. */
export function isMultipleOf(value: number, step: number): boolean {
  if (step <= 0) return true
  const ratio = value / step
  return Math.abs(ratio - Math.round(ratio)) < 1e-9
}

export function clampToStep(value: number, step: number, min: number, max: number | null): number {
  const snapped = Math.round(value / step) * step
  const bounded = Math.max(min, max === null ? snapped : Math.min(max, snapped))
  return Number(bounded.toFixed(3))
}
