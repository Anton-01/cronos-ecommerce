import { useEffect, useState } from 'react'
import Cropper from 'react-easy-crop'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { cropToBlob, type PixelArea } from '@/lib/cropImage'

export function AvatarCropper({ file, onCancel, onDone }: { file: File | null; onCancel: () => void; onDone: (blob: Blob) => Promise<void> }) {
  return (
    <Dialog open={file !== null} onOpenChange={(open) => !open && onCancel()}>
      <DialogContent>{file && <CropBody key={`${file.name}-${file.size}`} file={file} onCancel={onCancel} onDone={onDone} />}</DialogContent>
    </Dialog>
  )
}

function CropBody({ file, onCancel, onDone }: { file: File; onCancel: () => void; onDone: (blob: Blob) => Promise<void> }) {
  const [src, setSrc] = useState<string | null>(null)
  const [crop, setCrop] = useState({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [area, setArea] = useState<PixelArea | null>(null)
  const [busy, setBusy] = useState(false)

  // A data URL needs no cleanup (object URLs get revoked twice under StrictMode) and the file is capped at 5 MB.
  useEffect(() => {
    const reader = new FileReader()
    reader.onload = () => setSrc(typeof reader.result === 'string' ? reader.result : null)
    reader.readAsDataURL(file)
    return () => reader.abort()
  }, [file])

  const confirm = async () => {
    if (!src || !area) return
    setBusy(true)
    try {
      await onDone(await cropToBlob(src, area))
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>Ajusta tu foto</DialogTitle>
        <DialogDescription>Arrastra para encuadrar y usa el control para acercar.</DialogDescription>
      </DialogHeader>
      <div className="relative h-72 overflow-hidden rounded-lg bg-black" data-testid="cropper">
        {src && <Cropper image={src} crop={crop} zoom={zoom} aspect={1} cropShape="round" showGrid={false} onCropChange={setCrop} onZoomChange={setZoom} onCropComplete={(_, pixels) => setArea(pixels)} />}
      </div>
      <label className="flex items-center gap-3 text-sm">
        Zoom
        <input type="range" min={1} max={3} step={0.05} value={zoom} onChange={(e) => setZoom(Number(e.target.value))} className="flex-1" aria-label="Zoom" />
      </label>
      <div className="flex justify-end gap-2">
        <Button variant="outline" onClick={onCancel} disabled={busy}>
          Cancelar
        </Button>
        <Button onClick={() => void confirm()} disabled={!area || busy}>
          {busy ? 'Subiendo…' : 'Guardar foto'}
        </Button>
      </div>
    </>
  )
}
