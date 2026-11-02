import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import { ContactMessagesDocument, SetContactMessageStatusDocument, type ContactMessagesQuery } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

type Message = ContactMessagesQuery['contactMessages']['rows'][number]

const FILTERS = [
  { value: '', label: 'Todos' },
  { value: 'new', label: 'Nuevos' },
  { value: 'read', label: 'Leídos' },
  { value: 'replied', label: 'Respondidos' },
  { value: 'spam', label: 'Spam' },
]
const STATUS_LABEL: Record<string, string> = { new: 'Nuevo', read: 'Leído', replied: 'Respondido', spam: 'Spam' }
const TOPIC_LABEL: Record<string, string> = { general: 'Consulta general', order: 'Sobre un pedido', custom: 'Pedido personalizado', wholesale: 'Mayoreo', other: 'Otro' }
const SIGNAL_LABEL: Record<string, string> = {
  honeypot: 'Llenó el campo trampa', too_fast: 'Enviado demasiado rápido', duplicate: 'Mensaje repetido', many_links: 'Muchos enlaces', bad_name: 'Nombre sospechoso',
  repeated_characters: 'Caracteres repetidos', unexpected_script: 'Alfabeto inesperado', spam_phrase: 'Frase típica de spam', human_check_unavailable: 'Cloudflare no respondió', no_timer: 'Sin cronómetro',
}
const HUMAN_LABEL: Record<string, string> = { passed: 'Verificado (Turnstile)', skipped: 'Sin verificación configurada', unavailable: 'Cloudflare no disponible' }

/** The inbox of the contact form. Spam is kept (flagged) so a person wrongly caught can still be found and answered. */
export function MessagesPage() {
  const { can } = useAuth()
  const canManage = can('contact.manage')
  const clock = useClock()
  const run = useRun()
  const [status, setStatus] = useState('new')
  const [search, setSearch] = useState('')
  const [open, setOpen] = useState<Message | null>(null)
  const { data, loading, refetch } = useQuery(ContactMessagesDocument, { variables: { status: status || null, search: search || null, first: 50, offset: 0 }, fetchPolicy: 'cache-and-network' })
  const [setState] = useMutation(SetContactMessageStatusDocument)

  const mark = (m: Message, next: string) => run(async () => { const r = await setState({ variables: { id: m.id, status: next } }); setOpen(r.data!.setContactMessageStatus); await refetch() }, `Marcado como ${STATUS_LABEL[next]!.toLowerCase()}.`)
  const rows = data?.contactMessages.rows ?? []

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Mensajes" description="Lo que escriben los clientes en el formulario de contacto. Cada mensaje nuevo también llega por correo a la tienda, con «Responder» dirigido al cliente." />
      <div className="flex flex-wrap items-center gap-2">
        {FILTERS.map((f) => <Button key={f.value} size="sm" variant={status === f.value ? 'default' : 'outline'} aria-pressed={status === f.value} onClick={() => setStatus(f.value)}>{f.label}</Button>)}
        <Input type="search" aria-label="Buscar mensaje" placeholder="Buscar por nombre, correo, pedido o texto…" className="ml-auto max-w-xs" value={search} onChange={(e) => setSearch(e.target.value)} />
      </div>
      {loading && !data ? <Skeleton className="h-40 w-full" /> : rows.length === 0 ? <p className="text-sm text-muted-foreground">No hay mensajes en esta vista.</p> : (
        <ul className="divide-y rounded-md border">
          {rows.map((m) => (
            <li key={m.id}>
              <button type="button" className="flex w-full flex-wrap items-center justify-between gap-2 p-3 text-left text-sm hover:bg-muted/50" onClick={() => { setOpen(m); if (m.status === 'new' && canManage) void mark(m, 'read') }}>
                <span className="min-w-0 flex-1">
                  <span className={m.status === 'new' ? 'font-semibold' : ''}>{m.name}</span> <span className="text-muted-foreground">· {TOPIC_LABEL[m.topic] ?? m.topic}{m.orderNumber ? ` · ${m.orderNumber}` : ''}</span>
                  <span className="block truncate text-muted-foreground">{m.message}</span>
                </span>
                <span className="flex items-center gap-2"><Badge variant={m.status === 'new' ? 'default' : 'secondary'}>{STATUS_LABEL[m.status] ?? m.status}</Badge><time dateTime={m.createdAt} className="text-xs text-muted-foreground">{clock.formatDateTime(m.createdAt)}</time></span>
              </button>
            </li>
          ))}
        </ul>
      )}

      <Dialog open={open !== null} onOpenChange={(o) => !o && setOpen(null)}>
        <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
          {open && (
            <>
              <DialogHeader>
                <DialogTitle>{open.name}</DialogTitle>
                <DialogDescription>{open.email}{open.phone ? ` · ${open.phone}` : ''} · {TOPIC_LABEL[open.topic] ?? open.topic}{open.orderNumber ? ` · Pedido ${open.orderNumber}` : ''}</DialogDescription>
              </DialogHeader>
              <p className="whitespace-pre-wrap rounded-md border bg-muted/40 p-3 text-sm">{open.message}</p>
              <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <dt>Recibido</dt><dd>{clock.formatDateTime(open.createdAt)}</dd>
                <dt>Tienda</dt><dd>{open.marketCode ?? '—'} · idioma {open.locale}{open.country ? ` · país ${open.country}` : ''}</dd>
                <dt>Verificación</dt><dd>{HUMAN_LABEL[open.humanCheck] ?? open.humanCheck}</dd>
                {open.spamSignals.length > 0 && (<><dt>Señales</dt><dd>{open.spamSignals.map((s) => SIGNAL_LABEL[s] ?? s).join(', ')}</dd></>)}
              </dl>
              <div className="flex flex-wrap justify-end gap-2">
                <Button asChild size="sm" variant="outline"><a href={`mailto:${open.email}?subject=${encodeURIComponent('Re: ' + (TOPIC_LABEL[open.topic] ?? ''))}`}>Responder por correo</a></Button>
                {canManage && open.status !== 'replied' && <Button size="sm" onClick={() => mark(open, 'replied')}>Marcar como respondido</Button>}
                {canManage && open.status !== 'spam' && <Button size="sm" variant="outline" onClick={() => mark(open, 'spam')}>Es spam</Button>}
                {canManage && open.status === 'spam' && <Button size="sm" variant="outline" onClick={() => mark(open, 'new')}>No es spam</Button>}
              </div>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  )
}
