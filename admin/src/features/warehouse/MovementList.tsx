import { useClock } from '@/lib/clock'

import { signed } from './lib'

interface Entry {
  id: string
  type: string
  quantity: string
  balanceBefore: string
  balanceAfter: string
  locationCode: string
  counterpartCode: string | null
  userName: string | null
  userEmail: string | null
  createdAt: string
  note: string | null
  compensatedBy: string | null
}

/** A compact timeline: what happened, how much, who did it and when (in the visitor's clock, decided by IP). */
export function MovementList({ entries, labels }: { entries: Entry[]; labels: Record<string, string> }) {
  const clock = useClock()
  if (entries.length === 0) return <p className="rounded-lg border p-4 text-sm text-muted-foreground">Sin movimientos todavía.</p>
  return (
    <ol className="space-y-2">
      {entries.map((m) => {
        const n = Number(m.quantity)
        return (
          <li key={m.id} className="rounded-lg border p-3">
            <div className="flex items-baseline justify-between gap-3">
              <p className="font-medium">
                {labels[m.type] ?? m.type}
                {m.compensatedBy && <span className="ml-2 text-xs font-normal text-muted-foreground">(corregido)</span>}
              </p>
              <p className={`text-lg font-semibold tabular-nums ${n < 0 ? 'text-destructive' : 'text-success'}`}>{signed(m.quantity)}</p>
            </div>
            <p className="text-sm text-muted-foreground">
              {m.locationCode}
              {m.counterpartCode && (n < 0 ? ` → ${m.counterpartCode}` : ` ← ${m.counterpartCode}`)} · de {Number(m.balanceBefore).toLocaleString('es-MX')} a {Number(m.balanceAfter).toLocaleString('es-MX')}
            </p>
            <p className="text-xs text-muted-foreground">
              {m.userName ?? m.userEmail ?? 'Sistema'} · {clock.formatDateTime(m.createdAt)}
            </p>
            {m.note && <p className="mt-1 text-sm">{m.note}</p>}
          </li>
        )
      })}
    </ol>
  )
}
