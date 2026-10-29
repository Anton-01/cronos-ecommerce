import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import type { MediaItemFragment } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'
import { cn } from '@/lib/utils'

import { MediaDialog } from './MediaDialog'
import { MediaGrid } from './MediaGrid'
import { MediaToolbar } from './MediaToolbar'
import { useMediaLibrary } from './useMediaLibrary'
import { useMediaUpload } from './useMediaUpload'

export function MediaPage() {
  const { can } = useAuth()
  const library = useMediaLibrary()
  const { upload, uploading } = useMediaUpload()
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [dragging, setDragging] = useState(false)
  const canUpload = can('media.upload')
  const selected: MediaItemFragment | null = library.items.find((i) => i.id === selectedId) ?? null

  return (
    <div
      className={cn('space-y-4 rounded-xl', dragging && 'outline-2 outline-dashed outline-primary')}
      onDragOver={(e) => {
        if (!canUpload) return
        e.preventDefault()
        setDragging(true)
      }}
      onDragLeave={() => setDragging(false)}
      onDrop={(e) => {
        e.preventDefault()
        setDragging(false)
        if (canUpload && e.dataTransfer.files.length) void upload(e.dataTransfer.files)
      }}
    >
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Medios</h1>
          <p className="text-sm text-muted-foreground">
            Imágenes y documentos de la tienda.{canUpload ? ' Arrastra archivos aquí o usa el botón.' : ''}
          </p>
        </div>
        <MediaToolbar
          search={library.search}
          onSearch={library.setSearch}
          kind={library.kind}
          onKind={library.setKind}
          onFiles={(files) => void upload(files)}
          uploading={uploading}
          canUpload={canUpload}
        />
      </div>

      {library.error && (
        <div role="alert" className="flex items-center justify-between rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {friendlyMessage(parseApiError(library.error))}
          <Button size="sm" variant="outline" onClick={() => void library.refetch()}>
            Reintentar
          </Button>
        </div>
      )}

      {library.loading && library.items.length === 0 ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-6">
          {Array.from({ length: 12 }, (_, i) => (
            <Skeleton key={i} className="aspect-square" />
          ))}
        </div>
      ) : library.items.length === 0 ? (
        <p className="rounded-xl border border-dashed py-16 text-center text-sm text-muted-foreground">No hay archivos todavía.</p>
      ) : (
        <MediaGrid items={library.items} onSelect={(item) => setSelectedId(item.id)} selectedId={selectedId} />
      )}

      <div className="flex items-center justify-between text-sm">
        <span className="text-muted-foreground" aria-live="polite">
          {library.total === 0 ? 'Sin resultados' : `${library.from}–${library.to} de ${library.total}`}
          {library.processing ? ' · procesando miniaturas…' : ''}
        </span>
        <div className="flex gap-2">
          <Button size="sm" variant="outline" disabled={!library.hasPrevious} onClick={library.previous}>
            Anterior
          </Button>
          <Button size="sm" variant="outline" disabled={!library.hasNext} onClick={library.next}>
            Siguiente
          </Button>
        </div>
      </div>

      <MediaDialog item={selected} onClose={() => setSelectedId(null)} />
    </div>
  )
}
