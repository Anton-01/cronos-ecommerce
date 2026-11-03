import { money } from '@/lib/format'
import type { Locale } from '@/lib/config'
import { fill, messages } from '@/lib/i18n'
import type { Pricing, Sales } from '@/lib/types'

/** Price as the shop's market expects it: tax-inclusive in Mexico, "+ tax" in the US; per metre, per piece or per roll. */
export function Price({ pricing, sales, locale, size = 'md' }: { pricing: Pricing | null; sales?: Sales; locale: Locale; size?: 'md' | 'lg' }) {
  const t = messages(locale)
  if (!pricing) return <span className="text-sm text-ink">—</span>
  const unit = sales ? (sales.unit === 'METER' ? t.perMeter : sales.unit === 'PIECE' ? t.perPiece : '') : ''
  return (
    <span className={`inline-flex flex-wrap items-baseline gap-x-2 ${size === 'lg' ? 'text-3xl' : 'text-base'}`}>
      <span className="font-semibold text-primary-ink" style={{ fontFamily: 'var(--font-heading)' }}>{money(pricing.price.amount, pricing.currency, locale)}</span>
      {pricing.compareAt && <del className="text-sm text-ink/70">{money(pricing.compareAt.amount, pricing.currency, locale)}</del>}
      {unit && <span className="text-xs text-ink">{unit}</span>}
      <span className="text-xs text-ink">{pricing.taxIncluded ? t.taxIncluded : t.plusTax}</span>
    </span>
  )
}

export { fill }
