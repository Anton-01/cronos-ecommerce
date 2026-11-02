/**
 * The URL scheme the API's canonicals and sitemap follow (backend `config/seo.php`, exposed by the `seoRoutes` query).
 * Mirrored here because routing is decided before any request can be made; `routes.test.ts` guards the shape.
 */
export const LOCALES = {
  'es-MX': { prefix: 'es-mx', product: 'productos', category: 'categorias', page: 'informacion', og: 'es_MX', label: 'Español' },
  'en-US': { prefix: 'en-us', product: 'products', category: 'categories', page: 'info', og: 'en_US', label: 'English' },
} as const

export type Locale = keyof typeof LOCALES
export const DEFAULT_LOCALE: Locale = 'es-MX'

export function localeFromPrefix(prefix: string): Locale | null {
  return (Object.keys(LOCALES) as Locale[]).find((l) => LOCALES[l].prefix === prefix) ?? null
}

export const prefixes = (): string[] => (Object.keys(LOCALES) as Locale[]).map((l) => LOCALES[l].prefix)

/** Builds an internal link in the visitor's language. */
export const href = {
  home: (l: Locale) => `/${LOCALES[l].prefix}`,
  product: (l: Locale, slug: string) => `/${LOCALES[l].prefix}/${LOCALES[l].product}/${slug}`,
  category: (l: Locale, slug: string) => `/${LOCALES[l].prefix}/${LOCALES[l].category}/${slug}`,
  info: (l: Locale, slug: string) => `/${LOCALES[l].prefix}/${LOCALES[l].page}/${slug}`,
  page: (l: Locale, path: string) => `/${LOCALES[l].prefix}/${path.replace(/^\//, '')}`,
}

export const SITE_URL = (process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000').replace(/\/$/, '')
export const API_URL = (process.env.API_URL ?? 'http://localhost:8000').replace(/\/$/, '')
