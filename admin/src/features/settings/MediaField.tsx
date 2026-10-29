import { ImageIcon } from 'lucide-react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { MediaPicker } from '@/features/media/MediaPicker'

export interface MediaValue {
  id: string
  url: string
}

export function MediaField({ label, value, onChange, disabled }: { label: string; value: MediaValue | null; onChange: (value: MediaValue | null) => void; disabled: boolean }) {
  const [open, setOpen] = useState(false)

  return (
    <div className="flex items-center gap-3">
      <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
        {value ? <img src={value.url} alt={label} className="size-full object-contain" /> : <ImageIcon className="size-6 text-muted-foreground" />}
      </div>
      {!disabled && (
        <div className="flex gap-2">
          <Button type="button" size="sm" variant="outline" onClick={() => setOpen(true)}>
            {value ? 'Cambiar' : 'Elegir imagen'}
          </Button>
          {value && (
            <Button type="button" size="sm" variant="ghost" onClick={() => onChange(null)}>
              Quitar
            </Button>
          )}
        </div>
      )}
      <MediaPicker open={open} onOpenChange={setOpen} onPick={(item) => onChange({ id: item.id, url: item.thumbUrl ?? item.url })} />
    </div>
  )
}
