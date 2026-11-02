import type { Image } from '@/lib/types'

/** The API sends WebP renditions with a srcset (never the original); the browser picks by width. Dimensions reserve the space (no layout shift). */
export function Picture({ image, alt, sizes, priority = false, className = '' }: { image: Image | null; alt?: string; sizes: string; priority?: boolean; className?: string }) {
  if (!image) return <Placeholder className={className} />
  return (
    // eslint-disable-next-line @next/next/no-img-element -- renditions are produced by the API; the Next optimizer would only re-encode them
    <img
      src={image.url}
      srcSet={image.srcset ?? undefined}
      sizes={sizes}
      alt={alt ?? image.alt ?? ''}
      width={image.width ?? undefined}
      height={image.height ?? undefined}
      loading={priority ? 'eager' : 'lazy'}
      fetchPriority={priority ? 'high' : undefined}
      decoding="async"
      className={className}
    />
  )
}

/** Neutral stand-in until the catalogue has real photos: a bow on a soft tint, so a missing picture never leaves a hole. */
export function Placeholder({ className = '' }: { className?: string }) {
  return (
    <div aria-hidden className={`grid place-items-center bg-surface-alt text-line ${className}`}>
      <svg viewBox="0 0 64 64" width="38%" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M32 30c-8-12-22-10-22-2s14 12 22 2Zm0 0c8-12 22-10 22-2S40 40 32 30Z" />
        <path d="M32 30 22 50m10-20 10 20" />
        <circle cx="32" cy="30" r="3" />
      </svg>
    </div>
  )
}
