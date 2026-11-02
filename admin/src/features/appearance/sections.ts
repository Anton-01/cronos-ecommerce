import type { HomeSection } from './types'

export const SECTION_LABEL: Record<string, string> = {
  hero: 'Portada (carrusel)',
  categories: 'Categorías',
  products: 'Productos',
  banner: 'Banner',
  text: 'Texto',
  brands: 'Marcas',
  collections: 'Colecciones',
  blog: 'Blog',
}

const BLANK = { 'es-MX': '', 'en-US': '' }

/** A new section with sensible settings, ready to be edited. The id is unique among the existing ones. */
export function newSection(type: string, existing: HomeSection[]): HomeSection {
  let n = 1
  let id = type
  while (existing.some((s) => s.id === id)) id = `${type}-${++n}`
  const settings: Record<string, Record<string, unknown>> = {
    hero: { autoplay: 6, slides: [] },
    categories: { title: { ...BLANK, 'es-MX': 'Nuestras categorías', 'en-US': 'Our categories' }, subtitle: { ...BLANK }, mode: 'auto', categoryIds: [], max: 10 },
    products: { title: { ...BLANK, 'es-MX': 'Novedades', 'en-US': 'New arrivals' }, source: 'newest', categoryId: null, productIds: [], limit: 10 },
    banner: { image: null, imageMobile: null, title: { ...BLANK }, text: { ...BLANK }, ctaLabel: { ...BLANK }, ctaUrl: null, align: 'left', overlay: 20, height: 'md' },
    text: { title: { ...BLANK }, body: { ...BLANK } },
  }
  return { id, type, enabled: true, settings: settings[type] ?? {} }
}

export function moveSection(sections: HomeSection[], from: number, to: number): HomeSection[] {
  if (from === to || from < 0 || to < 0 || from >= sections.length || to >= sections.length) return sections
  const next = [...sections]
  const [item] = next.splice(from, 1)
  next.splice(to, 0, item!)
  return next
}

export function setSection(sections: HomeSection[], id: string, patch: Partial<HomeSection>): HomeSection[] {
  return sections.map((s) => (s.id === id ? { ...s, ...patch } : s))
}

export function removeSection(sections: HomeSection[], id: string): HomeSection[] {
  return sections.filter((s) => s.id !== id)
}

/** A localized text with one language replaced, keeping the others. */
export function withLocale(text: Record<string, string> | undefined, locale: string, value: string): Record<string, string> {
  return { ...(text ?? {}), [locale]: value }
}
