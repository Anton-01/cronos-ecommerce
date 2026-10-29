import { FileText, ImageOff } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import type { MediaItemFragment } from '@/graphql/generated'
import { formatBytes } from '@/lib/format'
import { cn } from '@/lib/utils'

const STATUS_LABEL: Record<MediaItemFragment['status'], string> = {
  PENDING: 'En cola',
  PROCESSING: 'Procesando',
  READY: 'Listo',
  FAILED: 'Error',
}

export function MediaGrid({
  items,
  onSelect,
  selectedId,
}: {
  items: MediaItemFragment[]
  onSelect: (item: MediaItemFragment) => void
  selectedId?: string | null
}) {
  return (
    <ul className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
      {items.map((item) => (
        <li key={item.id}>
          <button
            type="button"
            onClick={() => onSelect(item)}
            aria-label={item.name}
            aria-pressed={selectedId === item.id}
            className={cn(
              'group block w-full overflow-hidden rounded-lg border bg-card text-left transition-shadow hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              selectedId === item.id && 'ring-2 ring-primary',
            )}
          >
            <div className="relative flex aspect-square items-center justify-center bg-muted">
              {item.isImage && item.thumbUrl ? (
                <img src={item.thumbUrl} alt={item.translations.find((t) => t.alt)?.alt ?? item.name} loading="lazy" className="size-full object-cover" />
              ) : item.isImage ? (
                <ImageOff className="size-8 text-muted-foreground" />
              ) : (
                <FileText className="size-8 text-muted-foreground" />
              )}
              {item.status !== 'READY' && (
                <Badge variant={item.status === 'FAILED' ? 'destructive' : 'secondary'} className="absolute left-2 top-2">
                  {STATUS_LABEL[item.status]}
                </Badge>
              )}
            </div>
            <div className="space-y-0.5 p-2">
              <div className="truncate text-sm font-medium">{item.name}</div>
              <div className="text-xs text-muted-foreground">{formatBytes(item.size)}</div>
            </div>
          </button>
        </li>
      ))}
    </ul>
  )
}
