'use client'

import { createContext, useContext, useMemo, useState } from 'react'

import type { Image } from '@/lib/types'

interface Ctx { images: Image[]; select: (variationId: string | null) => void }
const VariationImagesContext = createContext<Ctx | null>(null)

/**
 * Links the colour picker with the gallery: choosing a colour shows that variation's own photos, and going back to "nothing chosen" (or to a variation
 * with no photos of its own) shows the product's. Lives on the page as one client island; the gallery and the buy box stay separate components.
 */
export function VariationImages({ productImages, byVariation, children }: { productImages: Image[]; byVariation: Record<string, Image[]>; children: React.ReactNode }) {
  const [selected, setSelected] = useState<string | null>(null)
  const value = useMemo<Ctx>(() => {
    const own = selected ? byVariation[selected] : undefined
    return { images: own && own.length > 0 ? own : productImages, select: setSelected }
  }, [selected, byVariation, productImages])
  return <VariationImagesContext.Provider value={value}>{children}</VariationImagesContext.Provider>
}

export function useVariationImages(): Ctx | null {
  return useContext(VariationImagesContext)
}
