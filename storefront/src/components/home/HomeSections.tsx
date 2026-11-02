import Link from 'next/link'

import { Picture } from '@/components/Picture'
import { ProductCard } from '@/components/ProductCard'
import { link } from '@/components/Header'
import { href, type Locale } from '@/lib/config'
import { getProducts } from '@/lib/data'
import { messages } from '@/lib/i18n'
import type { CategoryNode, HomeSection, Market } from '@/lib/types'

import { Hero, type Slide } from './Hero'

function find(tree: CategoryNode[], id: string): CategoryNode | null {
  for (const n of tree) {
    if (n.id === id) return n
    const inner = find(n.children, id)
    if (inner) return inner
  }
  return null
}

/** Renders the sections the back office arranged, in that order. A type this build does not know is skipped, never an error. */
export async function HomeSections({ sections, locale, market, tree, shape }: { sections: HomeSection[]; locale: Locale; market: Market; tree: CategoryNode[]; shape: string }) {
  const out: React.ReactNode[] = []
  for (const section of sections) {
    const s = section.settings
    if (section.type === 'hero') {
      const slides = ((s.slides ?? []) as Slide[]).map((x) => ({ ...x, ctaUrl: x.ctaUrl ? link(locale, x.ctaUrl) : null }))
      const t = messages(locale)
      out.push(<Hero key={section.id} slides={slides} autoplay={Number(s.autoplay ?? 0)} labels={{ previous: t.previous, next: t.next, goTo: t.page }} />)
    } else if (section.type === 'categories') {
      const wanted = (s.categoryIds as string[] | undefined) ?? []
      const nodes = s.mode === 'manual' && wanted.length ? wanted.map((id) => find(tree, id)).filter((n): n is CategoryNode => n !== null) : tree
      out.push(<CategoriesBlock key={section.id} title={s.title as string} nodes={nodes.slice(0, Number(s.max ?? 10))} locale={locale} shape={shape} />)
    } else if (section.type === 'products') {
      out.push(<ProductsBlock key={section.id} title={s.title as string} locale={locale} market={market} tree={tree} categoryId={(s.categoryId as string | null) ?? null} limit={Number(s.limit ?? 10)} sort={s.source === 'best_selling' ? 'NEWEST' : 'NEWEST'} />)
    } else if (section.type === 'banner') {
      out.push(<Banner key={section.id} s={s} locale={locale} />)
    } else if (section.type === 'text') {
      out.push(
        <section key={section.id} className="container-x mt-12 max-w-3xl text-center">
          {s.title ? <h2 className="text-2xl md:text-3xl">{s.title as string}</h2> : null}
          {s.body ? <p className="mt-3 whitespace-pre-line">{s.body as string}</p> : null}
        </section>,
      )
    }
  }
  return <>{out}</>
}

function CategoriesBlock({ title, nodes, locale, shape }: { title: string; nodes: CategoryNode[]; locale: Locale; shape: string }) {
  if (nodes.length === 0) return null
  return (
    <section className="container-x mt-12">
      {title && <h2 className="mb-6 text-center text-2xl md:text-3xl">{title}</h2>}
      <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
        {nodes.map((c) => (
          <li key={c.id}>
            <Link href={href.category(locale, c.slug ?? '')} className="group flex flex-col items-center gap-3">
              <span className="relative block aspect-square w-full overflow-hidden bg-surface-alt" style={{ borderRadius: shape === 'square' ? 0 : shape === 'rounded' ? '24px' : '50%' }}>
                <Picture image={c.image ?? null} alt="" sizes="(min-width: 1024px) 18vw, 45vw" className="absolute inset-0 h-full w-full object-cover transition-transform duration-300 group-hover:scale-105" />
                <span className="absolute inset-x-0 bottom-[14%] mx-auto w-fit max-w-[80%] truncate rounded-full bg-white px-4 py-1 text-center text-sm font-semibold shadow-sm" style={{ fontFamily: 'var(--font-heading)', color: 'var(--c-heading)' }}>{c.name}</span>
              </span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

async function ProductsBlock({ title, locale, market, tree, categoryId, limit, sort }: { title: string; locale: Locale; market: Market; tree: CategoryNode[]; categoryId: string | null; limit: number; sort: string }) {
  const category = categoryId ? find(tree, categoryId)?.slug ?? undefined : undefined
  const { items } = await getProducts({ locale, market: market.code, category, sort, first: Math.min(Math.max(limit, 1), 24) }).catch(() => ({ items: [] }))
  if (items.length === 0) return null
  return (
    <section className="container-x mt-12">
      {title && <h2 className="mb-6 text-center text-2xl md:text-3xl">{title}</h2>}
      <ul className="product-grid">
        {items.map((p, i) => <li key={p.id}><ProductCard product={p} locale={locale} priority={i < 4} /></li>)}
      </ul>
    </section>
  )
}

function Banner({ s, locale }: { s: Record<string, any>; locale: Locale }) {
  const image = s.image as { url: string; srcset: string | null; width: number; height: number; alt: string } | null
  const url = s.ctaUrl ? link(locale, s.ctaUrl as string) : null
  return (
    <section className="container-x mt-12">
      <div className="relative overflow-hidden" style={{ borderRadius: 'var(--radius-card)', background: 'var(--c-primary-soft)' }}>
        {image && <Picture image={{ id: 'banner', url: image.url, thumbUrl: null, srcset: image.srcset, alt: image.alt, width: image.width, height: image.height }} sizes="100vw" className="h-56 w-full object-cover md:h-80" />}
        {image && Number(s.overlay) > 0 && <div aria-hidden className="absolute inset-0" style={{ background: `rgba(0,0,0,${Number(s.overlay) / 100})` }} />}
        <div className={`${image ? 'absolute inset-0' : 'py-12'} flex flex-col justify-center gap-3 px-6 md:px-16`} style={{ color: image ? '#fff' : undefined }}>
          {s.title ? <h2 className="text-3xl" style={{ color: image ? '#fff' : undefined }}>{s.title as string}</h2> : null}
          {s.text ? <p className="max-w-lg">{s.text as string}</p> : null}
          {s.ctaLabel && url ? <Link href={url} className="btn btn-primary w-fit">{s.ctaLabel as string}</Link> : null}
        </div>
      </div>
    </section>
  )
}
