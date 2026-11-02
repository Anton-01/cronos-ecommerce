import Link from 'next/link'

import { href, type Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'
import type { ProductSummary } from '@/lib/types'

import { Picture } from './Picture'
import { Price } from './Price'

export function ProductCard({ product, locale, priority = false }: { product: ProductSummary; locale: Locale; priority?: boolean }) {
  const t = messages(locale)
  if (!product.slug) return null
  const out = product.stockStatusIn === 'OUT_OF_STOCK'
  const sale = product.pricing?.onSale
  return (
    <article className="card group relative flex flex-col overflow-hidden">
      <Link href={href.product(locale, product.slug)} className="block" aria-label={product.name ?? ''}>
        <div className="relative aspect-square bg-white">
          <Picture image={product.image} alt={product.name ?? ''} sizes="(min-width: 1024px) 22vw, 48vw" priority={priority} className="absolute inset-0 h-full w-full object-contain p-3" />
          <div className="absolute left-2 top-2 flex flex-col gap-1">
            {sale && <span className="bg-sale px-2 text-xs font-semibold leading-6 text-white" style={{ borderRadius: 'var(--radius-badge)' }}>%</span>}
            {out && <span className="px-2 text-xs font-semibold leading-6 text-white" style={{ background: 'var(--c-danger)', borderRadius: 'var(--radius-badge)' }}>{t.outOfStock}</span>}
          </div>
        </div>
        <div className="space-y-1 p-3">
          <h3 className="line-clamp-2 text-sm" style={{ fontFamily: 'var(--font-heading)' }}>{product.name}</h3>
          <Price pricing={product.pricing} sales={product.sales} locale={locale} />
        </div>
      </Link>
    </article>
  )
}
