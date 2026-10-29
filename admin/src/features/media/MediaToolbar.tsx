import { Upload } from 'lucide-react'
import { useRef } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import type { MediaKind } from '@/graphql/generated'

export function MediaToolbar({
  search,
  onSearch,
  kind,
  onKind,
  onFiles,
  uploading,
  canUpload,
}: {
  search: string
  onSearch: (value: string) => void
  kind: MediaKind | null
  onKind: (value: MediaKind | null) => void
  onFiles: (files: FileList) => void
  uploading: number
  canUpload: boolean
}) {
  const input = useRef<HTMLInputElement>(null)

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Input type="search" aria-label="Buscar archivos" placeholder="Buscar por nombre…" className="w-full sm:w-64" value={search} onChange={(e) => onSearch(e.target.value)} />
      <select
        aria-label="Tipo de archivo"
        className="h-9 rounded-md border bg-background px-2 text-sm"
        value={kind ?? ''}
        onChange={(e) => onKind((e.target.value || null) as MediaKind | null)}
      >
        <option value="">Todos</option>
        <option value="IMAGE">Imágenes</option>
        <option value="DOCUMENT">Documentos</option>
      </select>
      {canUpload && (
        <>
          <input
            ref={input}
            type="file"
            multiple
            hidden
            accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
            aria-label="Elegir archivos"
            onChange={(e) => {
              if (e.target.files?.length) onFiles(e.target.files)
              e.target.value = ''
            }}
          />
          <Button onClick={() => input.current?.click()} disabled={uploading > 0}>
            <Upload /> {uploading > 0 ? `Subiendo ${uploading}…` : 'Subir archivos'}
          </Button>
        </>
      )}
    </div>
  )
}
