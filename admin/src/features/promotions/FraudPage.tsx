import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { useRun } from '@/features/catalog/products/useRun'
import { useClock } from '@/lib/clock'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import {
  AdminFraudChecksDocument,
  AdminFraudRulesDocument,
  CreateFraudRuleDocument,
  DeleteFraudRuleDocument,
  type FraudRuleType,
  type FraudVerdict,
  UpdateFraudRuleDocument,
  type AdminFraudRulesQuery,
} from '@/graphql/generated'

type Rule = AdminFraudRulesQuery['adminFraudRules'][number]

const RULE_LABEL: Record<string, string> = {
  VELOCITY_IP: 'Muchos intentos desde una IP',
  VELOCITY_EMAIL: 'Muchos intentos con un correo',
  FAILED_PAYMENTS: 'Pagos rechazados repetidos',
  AMOUNT_OVER: 'Monto alto',
  COUNTRY_MISMATCH: 'País de facturación distinto al de envío',
  HIGH_RISK_COUNTRY: 'País de alto riesgo',
}

const VERDICT: Record<string, { label: string; variant: 'success' | 'secondary' | 'destructive' }> = {
  ALLOW: { label: 'Permitido', variant: 'success' },
  REVIEW: { label: 'Revisar', variant: 'secondary' },
  BLOCK: { label: 'Bloqueado', variant: 'destructive' },
}

export function FraudPage() {
  return (
    <div className="max-w-4xl space-y-4">
      <PageHeader title="Antifraude" description="Cada pedido suma puntos por las reglas que dispara: desde 50 se retiene para revisión y desde 80 se bloquea." />
      <Tabs defaultValue="rules">
        <TabsList>
          <TabsTrigger value="rules">Reglas</TabsTrigger>
          <TabsTrigger value="checks">Evaluaciones</TabsTrigger>
        </TabsList>
        <TabsContent value="rules">
          <Rules />
        </TabsContent>
        <TabsContent value="checks">
          <Checks />
        </TabsContent>
      </Tabs>
    </div>
  )
}

const describeParams = (r: Rule): string => {
  const p = (r.params ?? {}) as Record<string, unknown>
  if (p.max) return `${p.max} en ${p.window_minutes} min`
  if (p.amounts) return Object.entries(p.amounts as Record<string, string>).map(([c, v]) => `${v} ${c}`).join(', ')
  if (p.countries) return (p.countries as string[]).join(', ')
  return ''
}

function Rules() {
  const { can } = useAuth()
  const canManage = can('fraud.manage')
  const run = useRun()
  const { data, loading } = useQuery(AdminFraudRulesDocument)
  const [remove] = useMutation(DeleteFraudRuleDocument, { refetchQueries: ['AdminFraudRules'] })
  const [update] = useMutation(UpdateFraudRuleDocument, { refetchQueries: ['AdminFraudRules'] })
  const [editing, setEditing] = useState<{ rule: Rule | null } | null>(null)

  return (
    <div className="space-y-3 pt-3">
      {canManage && (
        <div className="flex justify-end">
          <Button onClick={() => setEditing({ rule: null })}>
            <Plus /> Nueva regla
          </Button>
        </div>
      )}
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminFraudRules.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-10 text-center font-semibold tabular-nums" title="Puntos">
              +{r.score}
            </span>
            <span className="flex-1">
              <span className="font-medium">{r.name}</span> <span className="text-muted-foreground">· {RULE_LABEL[r.type]} {describeParams(r) && `(${describeParams(r)})`}</span>
            </span>
            {!r.isActive && <Badge variant="outline">Inactiva</Badge>}
            {canManage && (
              <>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => void run(() => update({ variables: { id: r.id, input: { name: r.name, type: r.type, params: r.params, score: r.score, isActive: !r.isActive } } }), r.isActive ? 'Regla desactivada' : 'Regla activada')}
                >
                  {r.isActive ? 'Desactivar' : 'Activar'}
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ rule: r })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: r.id } }), 'Regla eliminada')}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent>{editing && <RuleForm key={editing.rule?.id ?? 'new'} rule={editing.rule} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function RuleForm({ rule, onClose }: { rule: Rule | null; onClose: () => void }) {
  const run = useRun()
  const [create] = useMutation(CreateFraudRuleDocument, { refetchQueries: ['AdminFraudRules'] })
  const [update] = useMutation(UpdateFraudRuleDocument, { refetchQueries: ['AdminFraudRules'] })
  const p = (rule?.params ?? {}) as Record<string, unknown>
  const amounts = (p.amounts ?? {}) as Record<string, string>
  const [form, setForm] = useState({
    name: rule?.name ?? '',
    type: (rule?.type ?? 'VELOCITY_IP') as FraudRuleType,
    score: String(rule?.score ?? 30),
    isActive: rule?.isActive ?? true,
    max: String(p.max ?? 5),
    windowMinutes: String(p.window_minutes ?? 10),
    amountMxn: amounts.MXN ?? '',
    amountUsd: amounts.USD ?? '',
    countries: ((p.countries as string[] | undefined) ?? []).join(', '),
  })
  const velocity = form.type === 'VELOCITY_IP' || form.type === 'VELOCITY_EMAIL' || form.type === 'FAILED_PAYMENTS'

  const params = (): Record<string, unknown> => {
    if (velocity) return { max: Number(form.max), window_minutes: Number(form.windowMinutes) }
    if (form.type === 'AMOUNT_OVER') return { amounts: { ...(form.amountMxn ? { MXN: form.amountMxn } : {}), ...(form.amountUsd ? { USD: form.amountUsd } : {}) } }
    if (form.type === 'HIGH_RISK_COUNTRY') return { countries: form.countries.split(/[\s,]+/).filter(Boolean) }
    return {}
  }
  const valid = form.name.trim() && Number(form.score) >= 1 && Number(form.score) <= 100

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        const input = { name: form.name.trim(), type: form.type, params: params(), score: Number(form.score), isActive: form.isActive }
        void run(() => (rule ? update({ variables: { id: rule.id, input } }) : create({ variables: { input } })), 'Regla guardada').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>{rule ? 'Editar regla' : 'Nueva regla'}</DialogTitle>
        <DialogDescription>Los puntos se suman cuando la regla se cumple; el total decide si el pedido se permite, se revisa o se bloquea.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="fr-name" label="Nombre">
          <Input id="fr-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="fr-score" label="Puntos (1-100)">
          <Input id="fr-score" inputMode="numeric" value={form.score} onChange={(e) => setForm({ ...form, score: e.target.value })} />
        </Field>
        <Field id="fr-type" label="Tipo de regla">
          <NativeSelect id="fr-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as FraudRuleType })}>
            {Object.entries(RULE_LABEL).map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </NativeSelect>
        </Field>
        {velocity && (
          <>
            <Field id="fr-max" label="Intentos máximos">
              <Input id="fr-max" inputMode="numeric" value={form.max} onChange={(e) => setForm({ ...form, max: e.target.value })} />
            </Field>
            <Field id="fr-window" label="Ventana (minutos)">
              <Input id="fr-window" inputMode="numeric" value={form.windowMinutes} onChange={(e) => setForm({ ...form, windowMinutes: e.target.value })} />
            </Field>
          </>
        )}
        {form.type === 'AMOUNT_OVER' && (
          <>
            <Field id="fr-mxn" label="Monto en MXN">
              <Input id="fr-mxn" inputMode="decimal" value={form.amountMxn} onChange={(e) => setForm({ ...form, amountMxn: e.target.value })} />
            </Field>
            <Field id="fr-usd" label="Monto en USD">
              <Input id="fr-usd" inputMode="decimal" value={form.amountUsd} onChange={(e) => setForm({ ...form, amountUsd: e.target.value })} />
            </Field>
          </>
        )}
        {form.type === 'HIGH_RISK_COUNTRY' && (
          <Field id="fr-countries" label="Países (ISO, separados por coma)">
            <Input id="fr-countries" value={form.countries} onChange={(e) => setForm({ ...form, countries: e.target.value.toUpperCase() })} />
          </Field>
        )}
      </div>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isActive} onCheckedChange={(v) => setForm({ ...form, isActive: v === true })} />
        Regla activa
      </label>
      <Actions onClose={onClose} disabled={!valid} />
    </form>
  )
}

function Checks() {
  const clock = useClock()
  const [decision, setDecision] = useState<FraudVerdict | ''>('')
  const { data, loading } = useQuery(AdminFraudChecksDocument, { variables: { decision: decision || null } })

  return (
    <div className="space-y-3 pt-3">
      <NativeSelect aria-label="Filtrar por veredicto" className="w-48" value={decision} onChange={(e) => setDecision(e.target.value as FraudVerdict | '')}>
        <option value="">Todos</option>
        <option value="REVIEW">Para revisar</option>
        <option value="BLOCK">Bloqueados</option>
        <option value="ALLOW">Permitidos</option>
      </NativeSelect>
      {loading && !data && <Skeleton className="h-24 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminFraudChecks.map((c) => {
          const reasons = (c.reasons ?? []) as { rule: string; detail: string }[]
          return (
            <li key={c.id} className="space-y-1 px-3 py-2.5">
              <div className="flex flex-wrap items-center gap-3">
                <Badge variant={VERDICT[c.decision]?.variant}>{VERDICT[c.decision]?.label}</Badge>
                <span className="font-semibold tabular-nums">{c.score} pts</span>
                <span className="flex-1 text-sm text-muted-foreground">
                  {c.email ?? 'sin correo'} · {c.ip ?? 'sin IP'}
                </span>
                <span className="text-xs text-muted-foreground">{clock.formatDateTime(c.createdAt)}{c.country ? ` · ${c.country}` : ''}</span>
              </div>
              {reasons.length > 0 && <p className="text-xs text-muted-foreground">{reasons.map((r) => `${r.rule}: ${r.detail}`).join(' · ')}</p>}
            </li>
          )
        })}
        {data && data.adminFraudChecks.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin evaluaciones todavía.</li>}
      </ul>
    </div>
  )
}
