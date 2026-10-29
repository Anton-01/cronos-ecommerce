export interface PixelArea {
  x: number
  y: number
  width: number
  height: number
}

/** Renders the chosen square of the image into a size×size JPEG (fixed output keeps avatars light). */
export async function cropToBlob(src: string, area: PixelArea, size = 512): Promise<Blob> {
  const image = await new Promise<HTMLImageElement>((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('The image could not be read.'))
    img.src = src
  })

  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('Canvas is not available.')

  ctx.fillStyle = '#fff' // JPEG has no alpha: flatten transparent PNGs onto white
  ctx.fillRect(0, 0, size, size)
  ctx.drawImage(image, area.x, area.y, area.width, area.height, 0, 0, size, size)

  return new Promise((resolve, reject) => canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Could not encode the image.'))), 'image/jpeg', 0.9))
}
