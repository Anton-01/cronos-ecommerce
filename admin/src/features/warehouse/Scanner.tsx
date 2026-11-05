import jsQR from 'jsqr'
import { Camera, CameraOff } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'

import { Button } from '@/components/ui/button'

interface BarcodeDetectorLike {
  detect(source: CanvasImageSource): Promise<{ rawValue: string }[]>
}
declare global {
  interface Window {
    BarcodeDetector?: new (options: { formats: string[] }) => BarcodeDetectorLike
  }
}

/**
 * The phone's camera reading QR codes. Uses the browser's own detector where it exists (fast, battery friendly) and a small
 * decoder elsewhere. The camera only runs while this is on screen and switched on, and every track is stopped when it goes.
 */
export function Scanner({ onCode }: { onCode: (text: string) => void }) {
  const video = useRef<HTMLVideoElement>(null)
  const [on, setOn] = useState(false)
  const [problem, setProblem] = useState<string | null>(null)
  const handler = useRef(onCode)
  useEffect(() => {
    handler.current = onCode
  })

  useEffect(() => {
    if (!on) return
    let stop = false
    let stream: MediaStream | null = null
    let timer = 0
    const canvas = document.createElement('canvas')
    const detector = window.BarcodeDetector ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null

    const tick = async () => {
      const v = video.current
      if (stop || !v) return
      if (v.readyState >= 2 && v.videoWidth > 0) {
        try {
          let text: string | null = null
          if (detector) {
            text = (await detector.detect(v))[0]?.rawValue ?? null
          } else {
            canvas.width = v.videoWidth
            canvas.height = v.videoHeight
            const ctx = canvas.getContext('2d', { willReadFrequently: true })!
            ctx.drawImage(v, 0, 0)
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height)
            text = jsQR(img.data, img.width, img.height)?.data ?? null
          }
          if (text) {
            handler.current(text)
            setOn(false) // one read per switch-on: the caller decides what happens next
            return
          }
        } catch {
          // a frame that cannot be read is just skipped
        }
      }
      timer = window.setTimeout(() => void tick(), 180)
    }

    navigator.mediaDevices
      .getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
      .then((s) => {
        if (stop) return s.getTracks().forEach((t) => t.stop())
        stream = s
        if (video.current) {
          video.current.srcObject = s
          void video.current.play()
        }
        void tick()
      })
      .catch((e: unknown) => {
        setOn(false)
        setProblem(e instanceof DOMException && e.name === 'NotAllowedError' ? 'No hay permiso para usar la cámara. Actívalo en el navegador o escribe el código.' : 'No se pudo abrir la cámara. Escribe el código o búscalo por nombre.')
      })

    return () => {
      stop = true
      window.clearTimeout(timer)
      stream?.getTracks().forEach((t) => t.stop())
    }
  }, [on])

  const supported = typeof navigator !== 'undefined' && !!navigator.mediaDevices?.getUserMedia

  return (
    <div className="space-y-2">
      {on && (
        <div className="relative overflow-hidden rounded-lg border bg-black">
          <video ref={video} className="aspect-square w-full object-cover sm:aspect-video" muted playsInline aria-label="Vista de la cámara" />
          <div className="pointer-events-none absolute inset-8 rounded-lg border-2 border-white/80" aria-hidden />
        </div>
      )}
      {supported ? (
        <Button type="button" size="lg" className="h-14 w-full text-base" variant={on ? 'outline' : 'default'} onClick={() => { setProblem(null); setOn((v) => !v) }}>
          {on ? <CameraOff className="size-5" /> : <Camera className="size-5" />}
          {on ? 'Apagar cámara' : 'Escanear con la cámara'}
        </Button>
      ) : (
        <p className="text-sm text-muted-foreground">Este navegador no puede abrir la cámara (se necesita una conexión segura). Escribe el código.</p>
      )}
      <p role="status" aria-live="polite" className="min-h-5 text-sm text-destructive">
        {problem}
      </p>
    </div>
  )
}
