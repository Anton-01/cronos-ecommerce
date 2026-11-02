import { FlipHorizontal, FlipVertical, RotateCw } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import Cropper from 'react-easy-crop'

import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'

import { aspectFor, cropProblem, toCropInput, type CropInput, type PixelArea } from './crop'

interface Props {
  file: File | null
  title: string
  /** Width/height the slot wants; null lets the person choose any shape (logos). */
  ratio: number | null
  min: { width: number; height: number }
  allowTrim?: boolean
  onCancel: () => void
  onConfirm: (crop: CropInput | null) => void
  busy?: boolean
}

/** Crop, rotate and flip a picture before it is uploaded. The server does the real work on the original; this only says where. */
export function CropDialog({ file, title, ratio, min, allowTrim, onCancel, onConfirm, busy }: Props) {
  const url = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  const [crop, setCrop] = useState({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [rotation, setRotation] = useState(0)
  const [flipX, setFlipX] = useState(false)
  const [flipY, setFlipY] = useState(false)
  const [trim, setTrim] = useState(false)
  const [area, setArea] = useState<PixelArea | null>(null)
  const [natural, setNatural] = useState<{ width: number; height: number } | null>(null)

  useEffect(() => {
    if (!url) return
    const img = new Image()
    img.onload = () => setNatural({ width: img.naturalWidth, height: img.naturalHeight })
    img.src = url
    return () => URL.revokeObjectURL(url)
  }, [url])

  // The server crops the ORIGINAL first and rotates/flips the result, so the frame is drawn for the picture as it will end up:
  // a quarter turn swaps the frame's shape, and the crop coordinates stay in the original's own axes.
  const wanted = aspectFor(ratio)
  const quarter = rotation === 90 || rotation === 270
  const frameAspect = wanted !== undefined ? (quarter ? 1 / wanted : wanted) : natural ? natural.width / natural.height : 1
  const problem = area ? cropProblem(area, quarter ? { width: min.height, height: min.width } : min) : null
  const isSvg = file?.type === 'image/svg+xml'

  return (
    <Dialog open={file !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>Mueve y acerca la imagen para elegir el encuadre. La original se conserva sin cambios.</DialogDescription>
        </DialogHeader>
        {isSvg ? (
          <p className="rounded-md border p-4 text-sm text-muted-foreground">Los SVG no se recortan: se limpian y se usan tal cual, y se ven nítidos en cualquier tamaño.</p>
        ) : (
          url && (
            <div className="relative h-72 overflow-hidden rounded-md bg-muted" data-testid="crop-area">
              <Cropper
                image={url}
                crop={crop}
                zoom={zoom}
                aspect={frameAspect}
                onCropChange={setCrop}
                onZoomChange={setZoom}
                onCropComplete={(_, pixels) => setArea(pixels)}
              />
            </div>
          )
        )}
        {!isSvg && (
          <div className="flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2 text-sm">
              Zoom
              <input type="range" min={1} max={4} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} aria-label="Zoom" />
            </label>
            <Button type="button" size="sm" variant="outline" onClick={() => setRotation((r) => (r + 90) % 360)}>
              <RotateCw className="size-4" /> Girar 90°
            </Button>
            <Button type="button" size="sm" variant={flipX ? 'default' : 'outline'} aria-pressed={flipX} onClick={() => setFlipX((v) => !v)}>
              <FlipHorizontal className="size-4" /> Espejo
            </Button>
            <Button type="button" size="sm" variant={flipY ? 'default' : 'outline'} aria-pressed={flipY} onClick={() => setFlipY((v) => !v)}>
              <FlipVertical className="size-4" /> Voltear
            </Button>
            {allowTrim && (
              <div className="flex items-center gap-2">
                <Checkbox id="trim" checked={trim} onCheckedChange={(v) => setTrim(v === true)} />
                <Label htmlFor="trim">Quitar márgenes vacíos</Label>
              </div>
            )}
          </div>
        )}
        {!isSvg && <p className="text-xs text-muted-foreground">El giro y el espejo se aplican después de recortar.</p>}
        {problem && <p role="alert" className="text-sm text-destructive">{problem}</p>}
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onCancel}>Cancelar</Button>
          <Button
            disabled={busy || (!isSvg && (!area || !natural))}
            onClick={() => onConfirm(isSvg || !area || !natural ? null : toCropInput(area, natural, { rotate: rotation, flipX, flipY, trim }))}
          >
            {busy ? 'Procesando…' : 'Usar esta imagen'}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
