import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import type { MediaItemFragment } from '@/graphql/generated'

import { MediaGrid } from './MediaGrid'
import { useMediaLibrary } from './useMediaLibrary'

/** Pick an existing image from the library (used for logos, favicon and social images). */
export function MediaPicker({ open, onOpenChange, onPick }: { open: boolean; onOpenChange: (open: boolean) => void; onPick: (item: MediaItemFragment) => void }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto">{open && <PickerBody onPick={onPick} onOpenChange={onOpenChange} />}</DialogContent>
    </Dialog>
  )
}

function PickerBody({ onPick, onOpenChange }: { onPick: (item: MediaItemFragment) => void; onOpenChange: (open: boolean) => void }) {
  const library = useMediaLibrary({ pageSize: 12, kind: 'IMAGE' })

  return (
    <>
      <DialogHeader>
        <DialogTitle>Elegir imagen</DialogTitle>
        <DialogDescription>Selecciona una imagen de la biblioteca.</DialogDescription>
      </DialogHeader>
      <Input type="search" aria-label="Buscar imagen" placeholder="Buscar por nombre…" value={library.search} onChange={(e) => library.setSearch(e.target.value)} />
      {library.items.length === 0 ? (
        <p className="py-10 text-center text-sm text-muted-foreground">{library.loading ? 'Cargando…' : 'No hay imágenes. Súbelas desde Medios.'}</p>
      ) : (
        <MediaGrid
          items={library.items}
          onSelect={(item) => {
            onPick(item)
            onOpenChange(false)
          }}
        />
      )}
      <div className="flex justify-end gap-2">
        <Button size="sm" variant="outline" disabled={!library.hasPrevious} onClick={library.previous}>
          Anterior
        </Button>
        <Button size="sm" variant="outline" disabled={!library.hasNext} onClick={library.next}>
          Siguiente
        </Button>
      </div>
    </>
  )
}
