export interface PixelArea {
  x: number
  y: number
  width: number
  height: number
}

export interface CropInput extends PixelArea {
  rotate: number
  flipX: boolean
  flipY: boolean
  trim: boolean
}

/**
 * react-easy-crop reports its area in pixels of the ORIGINAL picture, but with fractions. The server wants whole pixels that stay inside the picture,
 * so they are rounded and clamped here (a crop that touches the edge must not overflow by one pixel).
 */
export function toCropInput(area: PixelArea, natural: { width: number; height: number }, options: { rotate?: number; flipX?: boolean; flipY?: boolean; trim?: boolean } = {}): CropInput {
  const x = Math.max(0, Math.min(natural.width - 1, Math.round(area.x)))
  const y = Math.max(0, Math.min(natural.height - 1, Math.round(area.y)))
  const width = Math.max(1, Math.min(natural.width - x, Math.round(area.width)))
  const height = Math.max(1, Math.min(natural.height - y, Math.round(area.height)))
  const rotate = ((Math.round((options.rotate ?? 0) / 90) * 90) % 360 + 360) % 360
  return { x, y, width, height, rotate, flipX: options.flipX ?? false, flipY: options.flipY ?? false, trim: options.trim ?? false }
}

/** Width/height wanted for a slot, or undefined when any shape will do (a logo). */
export function aspectFor(ratio: number | null | undefined): number | undefined {
  return ratio === null || ratio === undefined ? undefined : ratio
}

/** Words the size of a crop against what the slot needs, so the person sees the problem before the server refuses it. */
export function cropProblem(area: PixelArea, min: { width: number; height: number }): string | null {
  const w = Math.round(area.width)
  const h = Math.round(area.height)
  if (w < min.width || h < min.height) return `El recorte mide ${w}×${h} px y hacen falta al menos ${min.width}×${min.height} px: se vería borroso.`
  return null
}
