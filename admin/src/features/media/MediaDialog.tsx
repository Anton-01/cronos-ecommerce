import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { DeleteMediaDocument, SupportedLocalesDocument, UpdateMediaDocument, type MediaItemFragment } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'
import { formatBytes } from '@/lib/format'

export function MediaDialog({ item, onClose }: { item: MediaItemFragment | null; onClose: () => void }) {
  return (
    <Dialog open={item !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">{item && <MediaDetails key={item.id} item={item} onClose={onClose} />}</DialogContent>
    </Dialog>
  )
}

type Draft = Record<string, { alt: string; title: string; caption: string }>

function MediaDetails({ item, onClose }: { item: MediaItemFragment; onClose: () => void }) {
  const { can } = useAuth()
  const canEdit = can('media.update')
  const { data: localeData } = useQuery(SupportedLocalesDocument)
  const locales = localeData?.supportedLocales ?? []
  const [update, { loading: saving }] = useMutation(UpdateMediaDocument)
  const [remove, { loading: removing }] = useMutation(DeleteMediaDocument, { refetchQueries: ['MediaLibrary'] })
  const [name, setName] = useState(item.name)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const [draft, setDraft] = useState<Draft>(() =>
    Object.fromEntries(item.translations.map((t) => [t.locale, { alt: t.alt ?? '', title: t.title ?? '', caption: t.caption ?? '' }])),
  )

  const medium = item.conversions.find((c) => c.name === 'medium')
  const preview = medium?.url ?? item.url
  const field = (locale: string, key: 'alt' | 'title' | 'caption') => draft[locale]?.[key] ?? ''
  const setField = (locale: string, key: 'alt' | 'title' | 'caption', value: string) =>
    setDraft((d) => ({ ...d, [locale]: { alt: '', title: '', caption: '', ...d[locale], [key]: value } }))

  const save = async () => {
    try {
      await update({
        variables: {
          id: item.id,
          name: name.trim() || null,
          translations: Object.entries(draft).map(([locale, v]) => ({ locale, alt: v.alt || null, title: v.title || null, caption: v.caption || null })),
        },
      })
      toast.success('Cambios guardados')
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  const destroy = async () => {
    try {
      await remove({ variables: { id: item.id } })
      toast.success('Archivo eliminado')
      onClose()
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{item.name}</DialogTitle>
        <DialogDescription>
          {item.mimeType} · {formatBytes(item.size)}
          {item.width && item.height ? ` · ${item.width}×${item.height}` : ''}
        </DialogDescription>
      </DialogHeader>

      <div className="flex items-center justify-center rounded-lg bg-muted p-2">
        {item.isImage ? (
          <img src={preview} alt={draft['es-MX']?.alt || item.name} className="max-h-72 rounded object-contain" />
        ) : (
          <a href={item.url} target="_blank" rel="noreferrer" className="py-10 text-sm underline">
            Abrir archivo
          </a>
        )}
      </div>

      <div className="flex flex-wrap items-center gap-2 text-sm">
        <Badge variant={item.status === 'READY' ? 'success' : item.status === 'FAILED' ? 'destructive' : 'secondary'}>{item.status}</Badge>
        {item.failureReason && <span className="text-destructive">{item.failureReason}</span>}
        <span className="text-muted-foreground">{item.conversions.length} versiones generadas</span>
      </div>

      <div className="space-y-2">
        <Label htmlFor="media-name">Nombre</Label>
        <Input id="media-name" value={name} maxLength={150} disabled={!canEdit} onChange={(e) => setName(e.target.value)} />
        <p className="text-xs text-muted-foreground">Solo cambia el nombre visible: la URL del archivo no cambia.</p>
      </div>

      {item.isImage && locales.length > 0 && (
        <Tabs defaultValue={locales[0]?.code}>
          <TabsList>
            {locales.map((l) => (
              <TabsTrigger key={l.code} value={l.code}>
                {l.code}
              </TabsTrigger>
            ))}
          </TabsList>
          {locales.map((l) => (
            <TabsContent key={l.code} value={l.code} className="space-y-3">
              <div className="space-y-1.5">
                <Label htmlFor={`alt-${l.code}`}>Texto alternativo (ALT)</Label>
                <Input id={`alt-${l.code}`} value={field(l.code, 'alt')} maxLength={255} disabled={!canEdit} onChange={(e) => setField(l.code, 'alt', e.target.value)} />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor={`title-${l.code}`}>Título</Label>
                <Input id={`title-${l.code}`} value={field(l.code, 'title')} maxLength={255} disabled={!canEdit} onChange={(e) => setField(l.code, 'title', e.target.value)} />
              </div>
            </TabsContent>
          ))}
        </Tabs>
      )}

      <div className="flex items-center justify-between gap-2">
        {can('media.delete') ? (
          confirmDelete ? (
            <Button variant="destructive" size="sm" disabled={removing} onClick={() => void destroy()}>
              Confirmar eliminación
            </Button>
          ) : (
            <Button variant="outline" size="sm" onClick={() => setConfirmDelete(true)}>
              Eliminar
            </Button>
          )
        ) : (
          <span />
        )}
        {canEdit && (
          <Button disabled={saving} onClick={() => void save()}>
            Guardar
          </Button>
        )}
      </div>
    </>
  )
}
