import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Textarea } from '@/components/ui/textarea'
import { useAuth } from '@/features/auth/AuthProvider'
import { PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import { AdminPagesDocument, CreatePageDocument, DeletePageDocument, PublishPageDocument, UnpublishPageDocument, UpdatePageDocument, type AdminPagesQuery } from '@/graphql/generated'
import { parseApiError } from '@/lib/errors'

import { LOCALES } from '../appearance/types'

type Page = AdminPagesQuery['adminPages'][number]
interface Draft { id: string | null; key: string; markets: string; position: number; tr: Record<string, { slug: string; title: string; bodyMd: string; seoTitle: string; seoDescription: string }> }

const blankTr = () => Object.fromEntries(LOCALES.map((l) => [l.code, { slug: '', title: '', bodyMd: '', seoTitle: '', seoDescription: '' }]))

const toDraft = (p: Page | null): Draft =>
  p === null
    ? { id: null, key: '', markets: '', position: 0, tr: blankTr() }
    : { id: p.id, key: p.key, markets: p.markets.join(', '), position: p.position, tr: { ...blankTr(), ...Object.fromEntries(p.translations.map((t) => [t.locale, { slug: t.slug, title: t.title, bodyMd: t.bodyMd, seoTitle: t.seoTitle ?? '', seoDescription: t.seoDescription ?? '' }])) } }

/** Static pages (about us, privacy, shipping…): written in Markdown per language, shown per store, kept in the shop's cache until saved. */
export function PagesPage() {
  const { can } = useAuth()
  const canManage = can('content.manage')
  const canPublish = can('content.publish')
  const run = useRun()
  const { data, loading, refetch } = useQuery(AdminPagesDocument, { fetchPolicy: 'cache-and-network' })
  const [create] = useMutation(CreatePageDocument)
  const [update] = useMutation(UpdatePageDocument)
  const [publish] = useMutation(PublishPageDocument)
  const [unpublish] = useMutation(UnpublishPageDocument)
  const [remove] = useMutation(DeletePageDocument)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [problems, setProblems] = useState<Record<string, string[]>>({})

  const save = async () => {
    if (!draft) return
    const translations = LOCALES.map((l) => ({ locale: l.code, ...draft.tr[l.code]! })).filter((t) => t.title.trim() !== '' || t.bodyMd.trim() !== '')
      .map((t) => ({ locale: t.locale, slug: t.slug || null, title: t.title, bodyMd: t.bodyMd, seoTitle: t.seoTitle || null, seoDescription: t.seoDescription || null }))
    const markets = draft.markets.split(',').map((m) => m.trim()).filter(Boolean)
    setProblems({})
    try {
      if (draft.id) await update({ variables: { id: draft.id, markets, position: draft.position, translations } })
      else await create({ variables: { key: draft.key.trim(), markets, position: draft.position, translations } })
      await refetch()
      setDraft(null)
    } catch (e) {
      const err = parseApiError(e)
      const fields = (e as { errors?: { extensions?: { fields?: Record<string, string> } }[] }).errors?.[0]?.extensions?.fields ?? {}
      setProblems(Object.fromEntries(Object.entries(fields).map(([k, v]) => [k, [v]])))
      if (Object.keys(fields).length === 0) setProblems({ _: [err.message] })
    }
  }

  const rows = data?.adminPages ?? []
  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader
        title="Contenido"
        description="Páginas informativas (nosotros, envíos, privacidad…). Escribe en Markdown, elige las tiendas donde se muestran y publícalas."
        actions={canManage && <Button size="sm" onClick={() => setDraft(toDraft(null))}><Plus className="size-4" /> Nueva página</Button>}
      />
      {loading && !data ? <Skeleton className="h-40 w-full" /> : rows.length === 0 ? <p className="text-sm text-muted-foreground">Aún no hay páginas.</p> : (
        <ul className="divide-y rounded-md border">
          {rows.map((p) => (
            <li key={p.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
              <div>
                <p className="font-medium">{p.translations[0]?.title ?? p.key} <span className="text-xs text-muted-foreground">({p.key})</span></p>
                <p className="text-xs text-muted-foreground">{p.translations.map((t) => `${t.locale}: /${t.slug}`).join(' · ')} · {p.markets.length ? `Tiendas: ${p.markets.join(', ')}` : 'Todas las tiendas'}</p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={p.status === 'published' ? 'default' : 'secondary'}>{p.status === 'published' ? 'Publicada' : 'Borrador'}</Badge>
                {canManage && <Button size="sm" variant="outline" onClick={() => setDraft(toDraft(p))}>Editar</Button>}
                {canPublish && (p.status === 'published'
                  ? <Button size="sm" variant="outline" onClick={() => run(async () => { await unpublish({ variables: { id: p.id } }); await refetch() }, 'Página despublicada.')}>Despublicar</Button>
                  : <Button size="sm" onClick={() => run(async () => { await publish({ variables: { id: p.id } }); await refetch() }, 'Página publicada: la tienda ya la muestra.')}>Publicar</Button>)}
                {canManage && <Button size="sm" variant="ghost" onClick={() => { if (window.confirm('¿Eliminar esta página?')) void run(async () => { await remove({ variables: { id: p.id } }); await refetch() }, 'Página eliminada.') }}>Eliminar</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Dialog open={draft !== null} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
          {draft && (
            <>
              <DialogHeader>
                <DialogTitle>{draft.id ? 'Editar página' : 'Nueva página'}</DialogTitle>
                <DialogDescription>Los cambios se guardan como borrador de la página; publicar la hace visible en la tienda. Si ya está publicada, guardar actualiza la tienda al instante.</DialogDescription>
              </DialogHeader>
              <div className="grid gap-3 sm:grid-cols-3">
                <div className="space-y-1"><Label htmlFor="pk">Clave</Label><Input id="pk" disabled={draft.id !== null} value={draft.key} placeholder="privacy" onChange={(e) => setDraft({ ...draft, key: e.target.value })} />{problems.key && <p className="text-xs text-destructive">{problems.key[0]}</p>}</div>
                <div className="space-y-1"><Label htmlFor="pm">Tiendas (códigos, vacío = todas)</Label><Input id="pm" value={draft.markets} placeholder="MX, US" onChange={(e) => setDraft({ ...draft, markets: e.target.value })} /></div>
                <div className="space-y-1"><Label htmlFor="pp">Orden</Label><Input id="pp" type="number" min={0} value={draft.position} onChange={(e) => setDraft({ ...draft, position: Number(e.target.value) })} /></div>
              </div>
              <Tabs defaultValue={LOCALES[0].code}>
                <TabsList>{LOCALES.map((l) => <TabsTrigger key={l.code} value={l.code}>{l.label}</TabsTrigger>)}</TabsList>
                {LOCALES.map((l) => {
                  const t = draft.tr[l.code]!
                  const set = (patch: Partial<typeof t>) => setDraft({ ...draft, tr: { ...draft.tr, [l.code]: { ...t, ...patch } } })
                  const bad = (f: string) => problems[`translations.${l.code}.${f}`]?.[0]
                  return (
                    <TabsContent key={l.code} value={l.code} className="space-y-3">
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1"><Label htmlFor={`t-${l.code}`}>Título</Label><Input id={`t-${l.code}`} value={t.title} onChange={(e) => set({ title: e.target.value })} />{bad('title') && <p className="text-xs text-destructive">{bad('title')}</p>}</div>
                        <div className="space-y-1"><Label htmlFor={`s-${l.code}`}>Dirección (vacío = del título)</Label><Input id={`s-${l.code}`} value={t.slug} onChange={(e) => set({ slug: e.target.value })} />{bad('slug') && <p className="text-xs text-destructive">{bad('slug')}</p>}</div>
                      </div>
                      <div className="space-y-1"><Label htmlFor={`b-${l.code}`}>Texto (Markdown)</Label><Textarea id={`b-${l.code}`} rows={14} className="font-mono text-sm" value={t.bodyMd} onChange={(e) => set({ bodyMd: e.target.value })} /><p className="text-xs text-muted-foreground">Admite títulos (##), listas, **negritas**, [enlaces](https://…) y tablas. El HTML escrito a mano se descarta.</p></div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1"><Label htmlFor={`st-${l.code}`}>Título para buscadores</Label><Input id={`st-${l.code}`} value={t.seoTitle} onChange={(e) => set({ seoTitle: e.target.value })} /></div>
                        <div className="space-y-1"><Label htmlFor={`sd-${l.code}`}>Descripción para buscadores</Label><Input id={`sd-${l.code}`} value={t.seoDescription} onChange={(e) => set({ seoDescription: e.target.value })} /></div>
                      </div>
                    </TabsContent>
                  )
                })}
              </Tabs>
              {(problems._ || problems.translations) && <p role="alert" className="text-sm text-destructive">{problems._?.[0] ?? problems.translations?.[0]}</p>}
              <div className="flex justify-end gap-2">
                <Button variant="outline" onClick={() => setDraft(null)}>Cancelar</Button>
                <Button onClick={save}>Guardar</Button>
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
