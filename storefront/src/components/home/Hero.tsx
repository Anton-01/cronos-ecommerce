'use client'

import Link from 'next/link'
import { useEffect, useState } from 'react'

export interface HeroImage { url: string; srcset: string | null; srcsetAvif?: string | null; width: number | null; height: number | null; alt: string; focal?: { x: number; y: number } | null }
export interface Slide { image: HeroImage | null; imageMobile: HeroImage | null; kicker?: string; title?: string; subtitle?: string; priceText?: string; ctaLabel?: string; ctaUrl: string | null; align: 'left' | 'center' | 'right'; overlay: number }

/** Full-width carousel. Works without JS (first slide), pauses on hover/focus, never autoplays for people who asked for less motion. */
export function Hero({ slides, autoplay, labels }: { slides: Slide[]; autoplay: number; labels: { previous: string; next: string; goTo: string } }) {
  const [index, setIndex] = useState(0)
  const [paused, setPaused] = useState(false)
  const count = slides.length

  useEffect(() => {
    if (autoplay <= 0 || count < 2 || paused) return
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    const id = setInterval(() => setIndex((i) => (i + 1) % count), autoplay * 1000)
    return () => clearInterval(id)
  }, [autoplay, count, paused])

  if (count === 0) return null
  const go = (n: number) => setIndex((n + count) % count)

  return (
    <section aria-roledescription="carousel" className="container-x mt-4" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)} onFocus={() => setPaused(true)} onBlur={() => setPaused(false)}>
      <div className="relative overflow-hidden" style={{ borderRadius: 'var(--radius-card)' }}>
        {slides.map((s, i) => {
          const hidden = i !== index
          // White text only where the picture is darkened enough to read it (overlay of 35 % or more); otherwise the theme's own dark text.
          const light = s.image !== null && s.overlay >= 35
          const align = s.align === 'center' ? 'items-center text-center' : s.align === 'right' ? 'items-end text-right' : 'items-start text-left'
          return (
            <div key={i} role="group" aria-roledescription="slide" aria-label={`${i + 1} / ${count}`} aria-hidden={hidden} hidden={hidden && i !== 0 ? true : undefined} className={hidden ? 'hidden' : 'relative block'}>
              <picture>
                {s.imageMobile && <source media="(max-width: 767px)" srcSet={s.imageMobile.srcset ?? s.imageMobile.url} />}
                {s.image ? (
                  // eslint-disable-next-line @next/next/no-img-element -- renditions come from the API
                  <img src={s.image.url} srcSet={s.image.srcset ?? undefined} sizes="(min-width: 1400px) 1370px, 100vw" alt={s.image.alt || s.title || ''} width={s.image.width ?? undefined} height={s.image.height ?? undefined} loading={i === 0 ? 'eager' : 'lazy'} fetchPriority={i === 0 ? 'high' : undefined} className="aspect-[4/3] w-full object-cover md:aspect-[12/5]" style={s.image.focal ? { objectPosition: `${s.image.focal.x * 100}% ${s.image.focal.y * 100}%` } : undefined} />
                ) : (
                  <div className="aspect-[4/3] w-full bg-primary-soft md:aspect-[12/5]" />
                )}
              </picture>
              {s.image && s.overlay > 0 && <div aria-hidden className="absolute inset-0" style={{ background: `rgba(0,0,0,${s.overlay / 100})` }} />}
              <div className={`absolute inset-0 flex flex-col justify-center gap-3 px-6 md:px-16 ${align}`}>
                {s.kicker && <p className="text-sm font-semibold uppercase" style={{ color: light ? '#fff' : 'var(--c-primary-ink)' }}>{s.kicker}</p>}
                {s.title && <h2 className="max-w-xl text-3xl md:text-5xl" style={{ color: light ? '#fff' : undefined }}>{s.title}</h2>}
                {s.subtitle && <p className="max-w-lg text-base md:text-lg" style={{ color: light ? '#fff' : undefined }}>{s.subtitle}</p>}
                {s.priceText && <p className="text-xl font-semibold" style={{ color: light ? '#fff' : 'var(--c-primary-ink)' }}>{s.priceText}</p>}
                {s.ctaLabel && s.ctaUrl && <Link href={s.ctaUrl} className="btn btn-primary mt-2">{s.ctaLabel}</Link>}
              </div>
            </div>
          )
        })}
        {count > 1 && (
          <>
            <button type="button" aria-label={labels.previous} onClick={() => go(index - 1)} className="absolute left-3 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-black shadow md:grid">‹</button>
            <button type="button" aria-label={labels.next} onClick={() => go(index + 1)} className="absolute right-3 top-1/2 hidden size-10 -translate-y-1/2 place-items-center rounded-full bg-white/90 text-black shadow md:grid">›</button>
            <div className="absolute inset-x-0 bottom-3 flex justify-center">
              <div className="flex gap-2 rounded-full bg-white/80 px-3 py-1.5">
                {slides.map((_, i) => <button key={i} type="button" aria-label={`${labels.goTo} ${i + 1}`} aria-current={i === index} onClick={() => go(i)} className="grid size-6 place-items-center rounded-full"><span aria-hidden className="block size-2.5 rounded-full" style={{ background: i === index ? 'var(--c-primary)' : '#767676' }} /></button>)}
              </div>
            </div>
          </>
        )}
      </div>
    </section>
  )
}
