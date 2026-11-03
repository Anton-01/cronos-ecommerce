'use client'

import { useState } from 'react'

import type { Image } from '@/lib/types'

import { useVariationImages } from './VariationImages'

import { Picture } from './Picture'

/** Main picture plus thumbnails. Without JS the first picture is still shown; the thumbnails are real buttons (keyboard and screen reader friendly). */
export function Gallery({ images: fallback, name }: { images: Image[]; name: string }) {
  const images = useVariationImages()?.images ?? fallback
  const [chosen, setI] = useState<{ set: Image[]; i: number }>({ set: images, i: 0 })
  // Another set of photos (a different colour was chosen) starts again from its first picture.
  const i = chosen.set === images ? chosen.i : 0
  const current = images[i] ?? null
  return (
    <div className="space-y-3">
      <div className="card relative aspect-square overflow-hidden bg-white">
        <Picture image={current} alt={current?.alt ?? name} sizes="(min-width: 1024px) 45vw, 100vw" priority className="absolute inset-0 h-full w-full object-contain p-4" />
      </div>
      {images.length > 1 && (
        <ul className="grid grid-cols-5 gap-2" aria-label={name}>
          {images.map((img, n) => (
            <li key={img.id}>
              <button type="button" aria-label={`${name} ${n + 1}`} aria-current={n === i} onClick={() => setI({ set: images, i: n })} className="card relative block aspect-square w-full overflow-hidden bg-white" style={{ outline: n === i ? '2px solid var(--c-primary)' : undefined }}>
                {/* eslint-disable-next-line @next/next/no-img-element -- renditions come from the API */}
                <img src={img.thumbUrl ?? img.url} alt="" loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-contain p-1" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
