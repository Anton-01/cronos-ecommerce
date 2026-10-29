import { useMutation } from '@apollo/client/react'
import { ArrowLeft, ArrowRight, Plus, X } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { MediaPicker } from '@/features/media/MediaPicker'
import { AttachProductMediaDocument, DetachProductMediaDocument, ReorderProductMediaDocument } from '@/graphql/generated'

import { type Product } from './tabs'
import { useRun } from './useRun'

export function GalleryTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const run = useRun()
  const [open, setOpen] = useState(false)
  const [attach] = useMutation(AttachProductMediaDocument)
  const [detach] = useMutation(DetachProductMediaDocument)
  const [reorder] = useMutation(ReorderProductMediaDocument)
  const ids = product.gallery.map((g) => g.id)

  const move = (index: number, delta: number) => {
    const next = [...ids]
    const [item] = next.splice(index, 1)
    next.splice(index + delta, 0, item!)
    return run(() => reorder({ variables: { id: product.id, mediaIds: next } }), 'Orden actualizado')
  }

  return (
    <Card>
      <CardHeader className="flex-row items-start justify-between gap-3">
        <div className="space-y-1.5">
          <CardTitle className="text-base">Galería</CardTitle>
          <CardDescription>La primera foto es la principal. Las fotos se eligen de la biblioteca de medios.</CardDescription>
        </div>
        {canEdit && (
          <Button variant="outline" onClick={() => setOpen(true)} disabled={ids.length >= 20}>
            <Plus /> Agregar foto
          </Button>
        )}
      </CardHeader>
      <CardContent>
        {product.gallery.length === 0 && <p className="rounded-xl border border-dashed py-10 text-center text-sm text-muted-foreground">Sin fotos todavía.</p>}
        <ul className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {product.gallery.map((g, i) => (
            <li key={g.id} className="overflow-hidden rounded-lg border">
              <div className="relative aspect-square bg-muted">
                <img src={g.thumbUrl ?? g.url} alt={g.alt ?? g.name} className="size-full object-cover" />
                {i === 0 && <Badge className="absolute left-2 top-2">Principal</Badge>}
                {g.status !== 'READY' && <Badge variant="secondary" className="absolute right-2 top-2">Procesando</Badge>}
              </div>
              {canEdit && (
                <div className="flex items-center justify-between p-1">
                  <Button size="icon" variant="ghost" aria-label={`Mover ${g.name} antes`} disabled={i === 0} onClick={() => void move(i, -1)}>
                    <ArrowLeft />
                  </Button>
                  <Button size="icon" variant="ghost" aria-label={`Quitar ${g.name}`} onClick={() => void run(() => detach({ variables: { id: product.id, mediaId: g.id } }), 'Foto quitada')}>
                    <X />
                  </Button>
                  <Button size="icon" variant="ghost" aria-label={`Mover ${g.name} después`} disabled={i === ids.length - 1} onClick={() => void move(i, 1)}>
                    <ArrowRight />
                  </Button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </CardContent>
      <MediaPicker open={open} onOpenChange={setOpen} onPick={(item) => void run(() => attach({ variables: { id: product.id, mediaId: item.id } }), 'Foto agregada')} />
    </Card>
  )
}
