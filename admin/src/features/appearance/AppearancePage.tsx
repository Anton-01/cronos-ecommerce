import { useMutation } from '@apollo/client/react'
import { Eye, History, Rocket, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { PageHeader } from '@/features/catalog/shared'
import { ApplyThemePresetDocument, DiscardThemeDraftDocument, PublishThemeDocument, ResetThemeDraftDocument, RestoreThemeVersionDocument, ThemePreviewLinkDocument } from '@/graphql/generated'

import { ColorsTab, FeaturesTab, HomeTab, IdentityTab, NavigationTab, StyleTab } from './AppearanceTabs'
import { Preview } from './Preview'
import { useThemeDraft } from './useThemeDraft'

const SAVE_LABEL = { idle: '', saving: 'Guardando…', saved: 'Borrador guardado', error: 'No se pudo guardar' } as const

export function AppearancePage() {
  const { can } = useAuth()
  const canEdit = can('appearance.manage')
  const canPublish = can('appearance.publish')
  const run = useRun()
  const d = useThemeDraft()
  const [versionsOpen, setVersionsOpen] = useState(false)
  const [publish] = useMutation(PublishThemeDocument)
  const [reset] = useMutation(ResetThemeDraftDocument)
  const [discard] = useMutation(DiscardThemeDraftDocument)
  const [restore] = useMutation(RestoreThemeVersionDocument)
  const [preset] = useMutation(ApplyThemePresetDocument)
  const [link] = useMutation(ThemePreviewLinkDocument)

  if (d.loading || !d.theme || !d.values) {
    return (
      <div className="max-w-6xl space-y-4" aria-busy="true">
        <PageHeader title="Apariencia" description="Identidad visual de la tienda." />
        <Skeleton className="h-96 w-full" />
      </div>
    )
  }
  const { theme, values } = d

  const doPublish = async () => {
    await d.saveNow()
    await run(async () => {
      await publish({ variables: { notes: null } })
      await d.refetch()
    }, 'Cambios publicados: la tienda ya los muestra.')
  }

  const doPreview = async () => {
    await d.saveNow()
    try {
      const { data } = await link()
      const url = data?.themePreviewLink
      if (url) {
        await navigator.clipboard?.writeText(url).catch(() => undefined)
        toast.success('Enlace de vista previa copiado. Vale 60 minutos.', { description: url })
      }
    } catch {
      toast.error('No se pudo crear el enlace de vista previa.')
    }
  }

  return (
    <div className="max-w-7xl space-y-4">
      <PageHeader
        title="Apariencia"
        description="Logotipo, colores, tipografía, menús y portada. Lo que cambies es un borrador hasta que lo publiques."
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs text-muted-foreground" role="status" aria-live="polite">{SAVE_LABEL[d.state]}</span>
            {theme.hasUnpublishedChanges && <Badge variant="secondary">Cambios sin publicar</Badge>}
            <Button size="sm" variant="outline" onClick={() => setVersionsOpen(true)}><History className="size-4" /> Versiones</Button>
            <Button size="sm" variant="outline" onClick={doPreview}><Eye className="size-4" /> Vista previa</Button>
            {canEdit && (
              <Button
                size="sm"
                variant="outline"
                disabled={!theme.hasUnpublishedChanges}
                onClick={() => run(async () => { await discard(); d.adopt((await d.refetch()).data!.adminTheme.draft) }, 'Borrador descartado.')}
              >
                <Undo2 className="size-4" /> Descartar
              </Button>
            )}
            {canPublish && (
              <Button size="sm" onClick={doPublish} disabled={!theme.hasUnpublishedChanges && d.state !== 'saving'}><Rocket className="size-4" /> Publicar</Button>
            )}
          </div>
        }
      />
      {d.error && <p role="alert" className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">{d.error}</p>}
      <div className="grid gap-4 lg:grid-cols-[1fr_380px]">
        <Tabs defaultValue="identity">
          <TabsList className="flex-wrap">
            <TabsTrigger value="identity">Identidad</TabsTrigger>
            <TabsTrigger value="colors">Colores</TabsTrigger>
            <TabsTrigger value="style">Estilo</TabsTrigger>
            <TabsTrigger value="navigation">Menús y avisos</TabsTrigger>
            <TabsTrigger value="home">Portada</TabsTrigger>
            <TabsTrigger value="features">Funciones</TabsTrigger>
          </TabsList>
          <TabsContent value="identity"><IdentityTab d={d} canEdit={canEdit} /></TabsContent>
          <TabsContent value="colors">
            <ColorsTab
              d={d}
              canEdit={canEdit}
              onPreset={(key) => run(async () => { const r = await preset({ variables: { key } }); d.adopt(r.data!.applyThemePreset) }, 'Preajuste aplicado al borrador.')}
              onReset={() => run(async () => { const r = await reset(); d.adopt(r.data!.resetThemeDraft) }, 'Borrador restablecido a los valores iniciales.')}
            />
          </TabsContent>
          <TabsContent value="style"><StyleTab d={d} canEdit={canEdit} /></TabsContent>
          <TabsContent value="navigation"><NavigationTab d={d} canEdit={canEdit} /></TabsContent>
          <TabsContent value="home"><HomeTab d={d} canEdit={canEdit} /></TabsContent>
          <TabsContent value="features"><FeaturesTab d={d} canEdit={canEdit} /></TabsContent>
        </Tabs>
        <aside aria-label="Vista previa" className="lg:sticky lg:top-4 lg:self-start">
          <Preview tokens={values.tokens} navigation={values.navigation} vars={d.cssVariables} fontsHref={d.fontsHref} logoUrl={(theme.draft.assets as Record<string, { url: string | null } | null>)?.logo?.url ?? null} />
        </aside>
      </div>

      <Dialog open={versionsOpen} onOpenChange={setVersionsOpen}>
        <DialogContent className="max-h-[80vh] max-w-lg overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Versiones</DialogTitle>
            <DialogDescription>Cada publicación queda guardada. Restaurar una la copia al borrador; no cambia la tienda hasta que publiques.</DialogDescription>
          </DialogHeader>
          <ul className="divide-y">
            {theme.versions.filter((v) => v.status !== 'draft').map((v) => (
              <li key={v.id} className="flex items-center justify-between gap-3 py-2 text-sm">
                <span>
                  #{v.number} <Badge variant={v.status === 'published' ? 'default' : 'secondary'}>{v.status === 'published' ? 'En línea' : 'Anterior'}</Badge>
                  <span className="ml-2 text-muted-foreground">{v.publishedAt ? new Date(v.publishedAt).toLocaleString() : ''}</span>
                </span>
                {canEdit && v.status !== 'published' && (
                  <Button size="sm" variant="outline" onClick={() => run(async () => { const r = await restore({ variables: { id: v.id } }); d.adopt(r.data!.restoreThemeVersion); setVersionsOpen(false); await d.refetch() }, `Versión #${v.number} copiada al borrador.`)}>Restaurar</Button>
                )}
              </li>
            ))}
          </ul>
        </DialogContent>
      </Dialog>
    </div>
  )
}
