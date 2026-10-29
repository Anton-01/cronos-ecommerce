import type { ProductFullFragment } from '@/graphql/generated'

export type Product = ProductFullFragment

export const translationOf = (p: Product, locale: string) => p.translations.find((t) => t.locale === locale)
export const displayName = (p: Product, locale: string) => translationOf(p, locale)?.name ?? p.translations[0]?.name ?? p.sku ?? p.id
