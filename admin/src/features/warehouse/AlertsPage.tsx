import { useMutation, useQuery } from '@apollo/client/react'
import { BellRing, Info, TriangleAlert } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect } from '@/features/catalog/shared'
import { WhAddSubscriberDocument, WhAlertCandidatesDocument, WhAlertInboxDocument, WhAlertProcessesDocument, WhMarkAlertsReadDocument, WhRemoveSubscriberDocument, WhSubscriptionLogDocument } from '@/graphql/generated'
import { useClock } from '@/lib/clock'

const SEVERITY: Record<string, { icon: typeof Info; cls: string; label: string }> = {
  critical: { icon: TriangleAlert, cls: 'border-destructive/40 bg-destructive/10', label: 'Crítica' },
  warning: { icon: BellRing, cls: 'border-amber-400/50 bg-amber-100/60 dark:bg-amber-950/30', label: 'Atención' },
  info: { icon: Info, cls: 'bg-card', label: 'Aviso' },
}

/** Everyone sees their own alerts; whoever manages alerts also chooses who receives each process. */
export function AlertsPage() {
  const { can } = useAuth()
  return (
    <Tabs defaultValue="inbox" className="max-w-3xl">
      <TabsList>
        <TabsTrigger value="inbox">Mis alertas</TabsTrigger>
        {can('warehouse.approve') && <TabsTrigger value="manage">Quién recibe qué</TabsTrigger>}
      </TabsList>
      <TabsContent value="inbox">
        <Inbox />
      </TabsContent>
      {can('warehouse.approve') && (
        <TabsContent value="manage">
          <Manage />
        </TabsContent>
      )}
    </Tabs>
  )
}

function Inbox() {
  const clock = useClock()
  const run = useRun()
  const [unreadOnly, setUnreadOnly] = useState(false)
  const { data, refetch } = useQuery(WhAlertInboxDocument, { variables: { unreadOnly, first: 100 }, fetchPolicy: 'cache-and-network', pollInterval: 30_000 })
  const [mark] = useMutation(WhMarkAlertsReadDocument, { refetchQueries: [WhAlertInboxDocument] })
  const items = data?.whAlertInbox.items ?? []
  const unread = data?.whAlertInbox.unread ?? 0

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4" checked={unreadOnly} onChange={(e) => setUnreadOnly(e.target.checked)} /> Solo sin leer ({unread})
        </label>
        <Button variant="outline" size="sm" disabled={unread === 0} onClick={() => void run(() => mark({ variables: { ids: null } }).then(() => refetch()), 'Todo marcado como leído')}>
          Marcar todo como leído
        </Button>
      </div>
      {items.length === 0 && <p className="rounded-lg border p-6 text-center text-sm text-muted-foreground">{unreadOnly ? 'No tienes alertas sin leer.' : 'Todavía no hay alertas.'}</p>}
      <ul className="space-y-2" aria-live="polite">
        {items.map((a) => {
          const s = SEVERITY[a.severity] ?? SEVERITY.info!
          return (
            <li key={a.id} className={`flex gap-3 rounded-lg border p-3 ${s.cls} ${a.read ? 'opacity-70' : ''}`}>
              <s.icon className="mt-0.5 size-5 shrink-0" aria-hidden />
              <div className="min-w-0 flex-1">
                <p className="flex flex-wrap items-center gap-2 font-medium">
                  {a.title}
                  <Badge variant="secondary">{s.label}</Badge>
                  {!a.read && <Badge variant="default">Nueva</Badge>}
                </p>
                <p className="text-sm">{a.detail}</p>
                <p className="text-xs text-muted-foreground">{clock.formatDateTime(a.createdAt)}</p>
              </div>
              {!a.read && (
                <Button variant="ghost" size="sm" onClick={() => void mark({ variables: { ids: [a.id] } })}>
                  Leída
                </Button>
              )}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function Manage() {
  const run = useRun()
  const clock = useClock()
  const { data, refetch } = useQuery(WhAlertProcessesDocument, { fetchPolicy: 'cache-and-network' })
  const { data: people } = useQuery(WhAlertCandidatesDocument)
  const { data: log, refetch: refetchLog } = useQuery(WhSubscriptionLogDocument, { variables: {}, fetchPolicy: 'cache-and-network' })
  const [add] = useMutation(WhAddSubscriberDocument)
  const [remove] = useMutation(WhRemoveSubscriberDocument)
  const [pick, setPick] = useState<Record<string, string>>({})
  const refresh = () => void Promise.all([refetch(), refetchLog()])
  const ACTION: Record<string, string> = { added: 'agregó a', changed: 'cambió la forma de avisar a', removed: 'quitó a' }

  return (
    <div className="space-y-5">
      <p className="text-sm text-muted-foreground">
        Elige qué personas del sistema reciben cada tipo de alerta, y si les llega a su bandeja, por correo o por ambos. Solo aparecen personas activas con acceso al administrador.
      </p>
      <ul className="space-y-3">
        {(data?.whAlertProcesses ?? []).map((p) => {
          const taken = new Set(p.subscribers.map((s) => s.userId))
          return (
            <li key={p.code} className="space-y-2 rounded-lg border p-3">
              <div>
                <p className="font-medium">{p.label}</p>
                <p className="text-xs text-muted-foreground">{p.description}</p>
              </div>
              <ul className="space-y-1">
                {p.subscribers.map((s) => (
                  <li key={s.userId} className="flex flex-wrap items-center justify-between gap-2 rounded-md bg-muted/50 px-3 py-1.5 text-sm">
                    <span>{s.email}</span>
                    <span className="flex items-center gap-3">
                      <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" className="size-4" checked={s.inbox} disabled={!s.emailEnabled} onChange={(e) => void run(() => add({ variables: { process: p.code, userId: s.userId, inbox: e.target.checked, email: s.emailEnabled } }).then(refresh), 'Cambio guardado')} /> Bandeja
                      </label>
                      <label className="flex items-center gap-1 text-xs">
                        <input type="checkbox" className="size-4" checked={s.emailEnabled} disabled={!s.inbox} onChange={(e) => void run(() => add({ variables: { process: p.code, userId: s.userId, inbox: s.inbox, email: e.target.checked } }).then(refresh), 'Cambio guardado')} /> Correo
                      </label>
                      <Button variant="ghost" size="sm" onClick={() => void run(() => remove({ variables: { process: p.code, userId: s.userId } }).then(refresh), 'Persona quitada')}>
                        Quitar
                      </Button>
                    </span>
                  </li>
                ))}
                {p.subscribers.length === 0 && <li className="text-xs text-muted-foreground">Nadie recibe esta alerta todavía.</li>}
              </ul>
              <div className="flex gap-2">
                <label htmlFor={`add-${p.code}`} className="sr-only">
                  Agregar persona a {p.label}
                </label>
                <NativeSelect id={`add-${p.code}`} value={pick[p.code] ?? ''} onChange={(e) => setPick({ ...pick, [p.code]: e.target.value })}>
                  <option value="">Agregar a…</option>
                  {(people?.whAlertCandidates ?? []).filter((c) => !taken.has(c.id)).map((c) => (
                    <option key={c.id} value={c.id}>
                      {c.email} ({c.roles.join(', ')})
                    </option>
                  ))}
                </NativeSelect>
                <Button
                  variant="outline"
                  disabled={!pick[p.code]}
                  onClick={() => void run(() => add({ variables: { process: p.code, userId: pick[p.code]!, inbox: true, email: true } }).then(() => { setPick({ ...pick, [p.code]: '' }); refresh() }), 'Persona agregada')}
                >
                  Agregar
                </Button>
              </div>
            </li>
          )
        })}
      </ul>

      <section aria-labelledby="sub-log" className="space-y-2">
        <h2 id="sub-log" className="font-semibold">
          Historial de cambios
        </h2>
        <ul className="divide-y rounded-lg border text-sm">
          {(log?.whSubscriptionLog ?? []).slice(0, 30).map((l, i) => (
            <li key={i} className="flex flex-wrap justify-between gap-2 px-3 py-2">
              <span>
                {l.doneByName ?? '—'} {ACTION[l.action] ?? l.action} {l.userName ?? l.userId} · <span className="text-muted-foreground">{data?.whAlertProcesses.find((p) => p.code === l.process)?.label ?? l.process}</span>
              </span>
              <span className="text-xs text-muted-foreground">{clock.formatDateTime(l.doneAt)}</span>
            </li>
          ))}
          {(log?.whSubscriptionLog ?? []).length === 0 && <li className="px-3 py-3 text-muted-foreground">Aún no hay cambios.</li>}
        </ul>
      </section>
    </div>
  )
}
