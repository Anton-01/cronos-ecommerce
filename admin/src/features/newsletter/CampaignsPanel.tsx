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
import { useRun } from '@/features/catalog/products/useRun'
import {
  CampaignPreviewDocument, CampaignsDocument, CancelCampaignDocument, CreateCampaignDocument, DeleteCampaignDocument, NewsletterSendingDocument, SendCampaignDocument, SendCampaignTestDocument, UpdateCampaignDocument,
  type CampaignsQuery,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'
import { parseApiError } from '@/lib/errors'

import { LOCALES } from '../appearance/types'

type Campaign = CampaignsQuery['campaigns'][number]
interface Draft { id: string | null; name: string; markets: string; locales: string[]; tr: Record<string, { subject: string; preheader: string; bodyMd: string }> }

const STATUS: Record<string, string> = { draft: 'Borrador', scheduled: 'Programada', sending: 'Enviando', sent: 'Enviada', cancelled: 'Cancelada' }
const blankTr = () => Object.fromEntries(LOCALES.map((l) => [l.code, { subject: '', preheader: '', bodyMd: '' }]))

const toDraft = (c: Campaign | null): Draft =>
  c === null
    ? { id: null, name: '', markets: '', locales: [], tr: blankTr() }
    : { id: c.id, name: c.name, markets: c.audience.markets.join(', '), locales: c.audience.locales, tr: { ...blankTr(), ...Object.fromEntries(c.translations.map((t) => [t.locale, { subject: t.subject, preheader: t.preheader, bodyMd: t.bodyMd }])) } }

/** Campaigns: written as drafts, previewed and tested, then sent now or at a time to the confirmed subscribers (through Resend). */
export function CampaignsPanel() {
  const { can } = useAuth()
  const canManage = can('newsletter.manage')
  const canSend = can('newsletter.send')
  const clock = useClock()
  const run = useRun()
  const { data: sending } = useQuery(NewsletterSendingDocument)
  const { data, loading, refetch } = useQuery(CampaignsDocument, { fetchPolicy: 'cache-and-network', pollInterval: 15000 })
  const [create] = useMutation(CreateCampaignDocument)
  const [update] = useMutation(UpdateCampaignDocument)
  const [remove] = useMutation(DeleteCampaignDocument)
  const [test] = useMutation(SendCampaignTestDocument)
  const [send] = useMutation(SendCampaignDocument)
  const [cancel] = useMutation(CancelCampaignDocument)
  const [draft, setDraft] = useState<Draft | null>(null)
  const [problems, setProblems] = useState<Record<string, string>>({})
  const [previewLocale, setPreviewLocale] = useState<string>(LOCALES[0].code)
  const [showPreview, setShowPreview] = useState(false)
  const [testTo, setTestTo] = useState('')
  const [when, setWhen] = useState('')
  const preview = useQuery(CampaignPreviewDocument, { variables: { id: draft?.id ?? '', locale: previewLocale }, skip: !showPreview || !draft?.id, fetchPolicy: 'network-only' })

  const campaigns = data?.campaigns ?? []
  const editable = (c: Draft | null) => c === null || c.id === null || ['draft', 'scheduled'].includes(campaigns.find((x) => x.id === c.id)?.status ?? 'draft')
  const payload = (d: Draft) => ({
    audience: { markets: d.markets.split(',').map((m) => m.trim()).filter(Boolean), locales: d.locales },
    translations: LOCALES.map((l) => ({ locale: l.code, ...d.tr[l.code]! })).filter((t) => t.subject.trim() !== '' || t.bodyMd.trim() !== '').map((t) => ({ locale: t.locale, subject: t.subject, preheader: t.preheader || null, bodyMd: t.bodyMd })),
  })

  const save = async (): Promise<string | null> => {
    if (!draft) return null
    setProblems({})
    try {
      const p = payload(draft)
      let id: string
      if (draft.id) id = (await update({ variables: { id: draft.id, name: draft.name, ...p } })).data!.updateCampaign.id
      else id = (await create({ variables: { name: draft.name, ...p } })).data!.createCampaign.id
      setDraft({ ...draft, id })
      await refetch()
      return id
    } catch (e) {
      const fields = (e as { errors?: { extensions?: { fields?: Record<string, string> } }[] }).errors?.[0]?.extensions?.fields ?? {}
      setProblems(Object.keys(fields).length ? fields : { _: parseApiError(e).message })
      return null
    }
  }

  const doSend = async (at: string | null) => {
    const id = await save()
    if (!id) return
    await run(async () => { await send({ variables: { id, at: at ? new Date(at).toISOString() : null } }); setDraft(null); await refetch() }, at ? 'Campaña programada.' : 'Campaña en camino: se envía por lotes.')
  }

  return (
    <div className="space-y-4">
      {sending && !sending.newsletterSending.configured && (
        <p role="status" className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">El envío con Resend aún no está configurado (faltan <code>RESEND_API_KEY</code> y <code>NEWSLETTER_FROM</code> en el servidor). Puedes redactar y previsualizar, pero no enviar.</p>
      )}
      {sending?.newsletterSending.configured && !sending.newsletterSending.webhookConfigured && (
        <p role="status" className="rounded-md border border-amber-500/50 bg-amber-500/10 p-3 text-sm">Falta <code>RESEND_WEBHOOK_SECRET</code>: sin él no nos enteramos de rebotes ni quejas y esas direcciones seguirían en la lista.</p>
      )}
      {canManage && <div><Button size="sm" onClick={() => { setDraft(toDraft(null)); setShowPreview(false) }}><Plus className="size-4" /> Nueva campaña</Button></div>}
      {loading && !data ? <Skeleton className="h-32 w-full" /> : campaigns.length === 0 ? <p className="text-sm text-muted-foreground">Aún no hay campañas.</p> : (
        <ul className="divide-y rounded-md border">
          {campaigns.map((c) => (
            <li key={c.id} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
              <div>
                <p className="font-medium">{c.name}</p>
                <p className="text-xs text-muted-foreground">
                  {c.translations.map((t) => t.subject).filter(Boolean)[0] ?? 'Sin asunto'}
                  {c.scheduledAt ? ` · programada ${clock.formatDateTime(c.scheduledAt)}` : ''}
                  {c.total > 0 ? ` · ${c.counts.sent}/${c.total} enviados · ${c.counts.delivered} entregados · ${c.counts.bounced} rebotes · ${c.counts.complained} quejas · ${c.counts.failed} fallidos` : ''}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge variant={c.status === 'sent' ? 'default' : 'secondary'}>{STATUS[c.status] ?? c.status}</Badge>
                <Button size="sm" variant="outline" onClick={() => { setDraft(toDraft(c)); setShowPreview(false) }}>{['draft', 'scheduled'].includes(c.status) && canManage ? 'Editar' : 'Ver'}</Button>
                {canSend && ['scheduled', 'sending'].includes(c.status) && <Button size="sm" variant="outline" onClick={() => { if (window.confirm('¿Cancelar? Lo que Resend ya aceptó no se puede recuperar.')) void run(async () => { await cancel({ variables: { id: c.id } }); await refetch() }, 'Campaña cancelada.') }}>Cancelar</Button>}
                {canManage && c.status === 'draft' && <Button size="sm" variant="ghost" onClick={() => { if (window.confirm('¿Eliminar este borrador?')) void run(async () => { await remove({ variables: { id: c.id } }); await refetch() }, 'Borrador eliminado.') }}>Eliminar</Button>}
              </div>
            </li>
          ))}
        </ul>
      )}

      <Dialog open={draft !== null} onOpenChange={(o) => !o && setDraft(null)}>
        <DialogContent className="max-h-[92vh] max-w-4xl overflow-y-auto">
          {draft && (
            <>
              <DialogHeader>
                <DialogTitle>{draft.id ? draft.name || 'Campaña' : 'Nueva campaña'}</DialogTitle>
                <DialogDescription>Se envía solo a suscriptores <b>confirmados</b>, cada uno en su idioma. Cada correo lleva el enlace de baja y la dirección de la tienda.</DialogDescription>
              </DialogHeader>
              <fieldset disabled={!editable(draft) || !canManage} className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-3">
                  <div className="space-y-1"><Label htmlFor="cn">Nombre interno</Label><Input id="cn" value={draft.name} onChange={(e) => setDraft({ ...draft, name: e.target.value })} />{problems.name && <p className="text-xs text-destructive">{problems.name}</p>}</div>
                  <div className="space-y-1"><Label htmlFor="cm">Tiendas (vacío = todas)</Label><Input id="cm" placeholder="MX, US" value={draft.markets} onChange={(e) => setDraft({ ...draft, markets: e.target.value })} /></div>
                  <div className="space-y-1"><Label>Idiomas (vacío = todos)</Label>
                    <div className="flex gap-3 pt-2">{LOCALES.map((l) => <label key={l.code} className="flex items-center gap-1 text-sm"><input type="checkbox" checked={draft.locales.includes(l.code)} onChange={(e) => setDraft({ ...draft, locales: e.target.checked ? [...draft.locales, l.code] : draft.locales.filter((x) => x !== l.code) })} />{l.code}</label>)}</div>
                  </div>
                </div>
                <Tabs defaultValue={LOCALES[0].code}>
                  <TabsList>{LOCALES.map((l) => <TabsTrigger key={l.code} value={l.code}>{l.label}</TabsTrigger>)}</TabsList>
                  {LOCALES.map((l) => {
                    const t = draft.tr[l.code]!
                    const set = (patch: Partial<typeof t>) => setDraft({ ...draft, tr: { ...draft.tr, [l.code]: { ...t, ...patch } } })
                    return (
                      <TabsContent key={l.code} value={l.code} className="space-y-3">
                        <div className="grid gap-3 sm:grid-cols-2">
                          <div className="space-y-1"><Label htmlFor={`s-${l.code}`}>Asunto</Label><Input id={`s-${l.code}`} value={t.subject} onChange={(e) => set({ subject: e.target.value })} />{problems[`translations.${l.code}.subject`] && <p className="text-xs text-destructive">{problems[`translations.${l.code}.subject`]}</p>}</div>
                          <div className="space-y-1"><Label htmlFor={`p-${l.code}`}>Texto de vista previa (opcional)</Label><Input id={`p-${l.code}`} value={t.preheader} onChange={(e) => set({ preheader: e.target.value })} /></div>
                        </div>
                        <div className="space-y-1"><Label htmlFor={`b-${l.code}`}>Mensaje (Markdown)</Label><Textarea id={`b-${l.code}`} rows={12} className="font-mono text-sm" value={t.bodyMd} onChange={(e) => set({ bodyMd: e.target.value })} />{problems[`translations.${l.code}.body`] && <p className="text-xs text-destructive">{problems[`translations.${l.code}.body`]}</p>}<p className="text-xs text-muted-foreground">Títulos (##), **negritas**, listas, [enlaces](https://…) e imágenes ![texto](https://…). El HTML escrito a mano se descarta.</p></div>
                      </TabsContent>
                    )
                  })}
                </Tabs>
              </fieldset>
              {problems._ && <p role="alert" className="text-sm text-destructive">{problems._}</p>}
              {problems.translations && <p role="alert" className="text-sm text-destructive">{problems.translations}</p>}

              {showPreview && draft.id && (
                <div className="space-y-2">
                  <div className="flex items-center gap-2 text-sm"><Label>Vista previa en</Label>{LOCALES.map((l) => <Button key={l.code} size="sm" variant={previewLocale === l.code ? 'default' : 'outline'} onClick={() => setPreviewLocale(l.code)}>{l.code}</Button>)}</div>
                  {preview.loading ? <Skeleton className="h-64 w-full" /> : preview.data ? (<><p className="text-sm"><b>Asunto:</b> {preview.data.campaignPreview.subject}</p><iframe title="Vista previa del correo" sandbox="" srcDoc={preview.data.campaignPreview.html} className="h-[28rem] w-full rounded-md border bg-white" /></>) : <p className="text-sm text-muted-foreground">Escribe el mensaje y guarda para verlo.</p>}
                </div>
              )}

              <div className="flex flex-wrap items-end justify-between gap-3 border-t pt-3">
                <div className="flex flex-wrap items-end gap-2">
                  <Button variant="outline" onClick={async () => { if (await save()) setShowPreview(true) }}>Guardar y previsualizar</Button>
                  {canManage && draft.id && (
                    <div className="flex items-end gap-2">
                      <div className="space-y-1"><Label htmlFor="tt">Enviar prueba a</Label><Input id="tt" type="email" placeholder="tu@correo.com" value={testTo} onChange={(e) => setTestTo(e.target.value)} className="w-52" /></div>
                      <Button variant="outline" disabled={!testTo || !sending?.newsletterSending.configured} onClick={() => run(async () => { const id = await save(); if (id) await test({ variables: { id, email: testTo, locale: previewLocale } }) }, 'Prueba enviada.')}>Enviar prueba</Button>
                    </div>
                  )}
                </div>
                {canSend && editable(draft) && (
                  <div className="flex flex-wrap items-end gap-2">
                    <div className="space-y-1"><Label htmlFor="cw">Programar</Label><Input id="cw" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} className="w-52" /></div>
                    <Button variant="outline" disabled={!when || !sending?.newsletterSending.configured} onClick={() => doSend(when)}>Programar</Button>
                    <Button disabled={!sending?.newsletterSending.configured} onClick={() => { if (window.confirm('¿Enviar ahora a todos los suscriptores confirmados de esta audiencia?')) void doSend(null) }}>Enviar ahora</Button>
                  </div>
                )}
                {canManage && editable(draft) && <Button variant="ghost" onClick={async () => { if (await save()) setDraft(null) }}>Guardar borrador</Button>}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
