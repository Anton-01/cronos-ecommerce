import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { AdminAttributesDocument, AdminCatalogListsDocument, AdminCatalogOptionsDocument, CreateCatalogOptionDocument, DeleteCatalogOptionDocument, UpdateCatalogOptionDocument, type AdminCatalogOptionsQuery } from '@/graphql/generated'

import { NativeSelect, PageHeader, useSupportedLocales } from '../shared'
import { useRun } from '../products/useRun'
import { codeFrom, labelOf } from './specs'

type Option = AdminCatalogOptionsQuery['adminCatalogOptions'][number]
type Meta = { sell_unit?: string; base?: boolean; presentation?: boolean; roll?: boolean; plural?: Record<string, string>; factor?: string }

const HELP: Record<string, string> = {
  packaging_type: 'Cómo viene empacado el producto (unitario, en conjunto, por caja…).',
  unit: 'Unidades en que se cuenta el producto (unidad base) y en que viene empacado (presentación).',
  weight_unit: 'Unidades en que se captura el peso. El factor son los gramos que vale una unidad (1 g = 1; 1 kg = 1000).',
  length_unit: 'Unidades en que se capturan las medidas. El factor son los milímetros que vale una unidad (1 mm = 1; 1 cm = 10; 1 m = 1000).',
}

/** The short lists of the client, each with create, rename, reorder, deactivate and delete. Colours, materials, finishes and widths are attributes (their own editor). */
export function OptionsPage() {
  const { data } = useQuery(AdminCatalogListsDocument)
  const { data: attrs } = useQuery(AdminAttributesDocument)
  const lists = data?.adminCatalogLists ?? []

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader title="Catálogos" description="Las listas que se eligen al dar de alta un producto. Agrega, renombra, ordena, desactiva o elimina lo que cambie en el negocio." />
      <Tabs defaultValue="packaging_type">
        <TabsList className="flex-wrap">
          {lists.map((l) => (
            <TabsTrigger key={l.code} value={l.code}>
              {l.label}
            </TabsTrigger>
          ))}
          <TabsTrigger value="attributes">Colores, materiales y más</TabsTrigger>
        </TabsList>
        {lists.map((l) => (
          <TabsContent key={l.code} value={l.code}>
            <OptionList catalog={l.code} />
          </TabsContent>
        ))}
        <TabsContent value="attributes" className="space-y-3">
          <p className="text-sm text-muted-foreground">Estas listas son atributos del catálogo: sirven también para filtrar en la tienda y para definir variantes. Se editan en su propia pantalla.</p>
          <ul className="divide-y rounded-lg border">
            {(attrs?.adminAttributes ?? []).map((a) => (
              <li key={a.id} className="flex items-center justify-between gap-3 px-4 py-3">
                <span>
                  <span className="font-medium">{labelOf(a.names, 'es-MX')}</span> <span className="text-xs text-muted-foreground">({a.code})</span>
                </span>
                <span className="flex items-center gap-3 text-sm text-muted-foreground">
                  {a.values.length} valores
                  <Button asChild variant="outline" size="sm">
                    <Link to="/catalog/attributes">Editar valores</Link>
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        </TabsContent>
      </Tabs>
    </div>
  )
}

function OptionList({ catalog }: { catalog: string }) {
  const { can } = useAuth()
  const run = useRun()
  const manage = can('catalog.manage')
  const { defaultLocale } = useSupportedLocales()
  const { data, refetch } = useQuery(AdminCatalogOptionsDocument, { variables: { catalog, includeInactive: true }, fetchPolicy: 'cache-and-network' })
  const [update] = useMutation(UpdateCatalogOptionDocument)
  const [remove] = useMutation(DeleteCatalogOptionDocument)
  const [editing, setEditing] = useState<Option | 'new' | null>(null)
  const rows = data?.adminCatalogOptions ?? []

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-3">
        <p className="text-sm text-muted-foreground">{HELP[catalog]}</p>
        {manage && (
          <Button onClick={() => setEditing('new')}>
            <Plus className="size-4" /> Agregar
          </Button>
        )}
      </div>
      <ul className="divide-y rounded-lg border">
        {rows.map((o) => {
          const m = (o.meta ?? {}) as Meta
          return (
            <li key={o.id} className={`flex flex-wrap items-center justify-between gap-3 px-4 py-3 ${o.isActive ? '' : 'opacity-60'}`}>
              <div className="min-w-0">
                <p className="font-medium">
                  {labelOf(o.labels, defaultLocale)} <span className="font-mono text-xs text-muted-foreground">{o.code}</span>
                </p>
                <p className="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                  {catalog === 'unit' && (
                    <>
                      <Badge variant="secondary">{m.sell_unit === 'meter' ? 'se mide en metros' : 'se cuenta por pieza'}</Badge>
                      {m.base && <Badge variant="outline">unidad base</Badge>}
                      {m.presentation && <Badge variant="outline">presentación</Badge>}
                      {m.roll && <Badge variant="outline">rollo</Badge>}
                    </>
                  )}
                  {(catalog === 'weight_unit' || catalog === 'length_unit') && <span>factor {m.factor}</span>}
                  {!o.isActive && <Badge variant="destructive">Desactivada</Badge>}
                  {o.inUse && <Badge variant="outline">en uso</Badge>}
                </p>
              </div>
              {manage && (
                <div className="flex items-center gap-2">
                  <Button variant="outline" size="sm" onClick={() => setEditing(o)}>
                    Editar
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => void run(() => update({ variables: { id: o.id, isActive: !o.isActive } }).then(() => refetch()), o.isActive ? 'Desactivada: ya no se ofrece' : 'Activada')}>
                    {o.isActive ? 'Desactivar' : 'Activar'}
                  </Button>
                  <Button variant="ghost" size="sm" disabled={o.inUse} title={o.inUse ? 'Algún producto la usa: desactívala en lugar de eliminarla' : undefined} onClick={() => void run(() => remove({ variables: { id: o.id } }).then(() => refetch()), 'Eliminada')}>
                    Eliminar
                  </Button>
                </div>
              )}
            </li>
          )
        })}
        {rows.length === 0 && <li className="px-4 py-6 text-center text-sm text-muted-foreground">Esta lista está vacía.</li>}
      </ul>
      {editing && <OptionDialog key={editing === 'new' ? 'new' : editing.id} catalog={catalog} option={editing === 'new' ? null : editing} nextPosition={rows.length} onClose={() => setEditing(null)} onDone={() => void refetch()} />}
    </div>
  )
}

function OptionDialog({ catalog, option, nextPosition, onClose, onDone }: { catalog: string; option: Option | null; nextPosition: number; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const { locales } = useSupportedLocales()
  const [create] = useMutation(CreateCatalogOptionDocument)
  const [update] = useMutation(UpdateCatalogOptionDocument)
  const m = (option?.meta ?? {}) as Meta
  const [labels, setLabels] = useState<Record<string, string>>(Object.fromEntries((option?.labels ?? []).map((l) => [l.locale, l.value ?? ''])))
  const [code, setCode] = useState(option?.code ?? '')
  const [position, setPosition] = useState(String(option?.position ?? nextPosition))
  const [sell, setSell] = useState(m.sell_unit ?? 'piece')
  const [base, setBase] = useState(m.base ?? true)
  const [presentation, setPresentation] = useState(m.presentation ?? false)
  const [roll, setRoll] = useState(m.roll ?? false)
  const [plural, setPlural] = useState<Record<string, string>>(m.plural ?? {})
  const [factor, setFactor] = useState(m.factor ?? '1')
  const defaultLocale = locales.find((l) => l.isDefault)?.code ?? 'es-MX'

  const meta = (): Record<string, unknown> | null =>
    catalog === 'unit' ? { sell_unit: sell, base, presentation, roll, plural } : catalog === 'weight_unit' || catalog === 'length_unit' ? { factor } : null
  const valid = (labels[defaultLocale] ?? '').trim() !== '' && (option !== null || /^[a-z0-9][a-z0-9_]{0,39}$/.test(code)) && (catalog === 'unit' ? base || presentation : true) && (!['weight_unit', 'length_unit'].includes(catalog) || Number(factor) > 0)

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            const list = locales.filter((l) => (labels[l.code] ?? '').trim()).map((l) => ({ locale: l.code, value: labels[l.code]!.trim() }))
            const p = Number(position) || 0
            void run(
              () => (option ? update({ variables: { id: option.id, labels: list, meta: meta(), position: p } }) : create({ variables: { catalog, code, labels: list, meta: meta(), position: p } })),
              option ? 'Cambios guardados' : 'Agregado',
            ).then((ok) => ok && (onDone(), onClose()))
          }}
        >
          <DialogHeader>
            <DialogTitle>{option ? 'Editar' : 'Agregar'}</DialogTitle>
            <DialogDescription>{option ? 'El código no cambia: los productos que ya lo usan siguen igual.' : 'El código identifica la opción para siempre; el nombre se puede cambiar cuando quieras.'}</DialogDescription>
          </DialogHeader>
          {locales.map((l) => (
            <Field key={l.code} id={`opt-${l.code}`} label={`Nombre (${l.code})`}>
              <Input
                id={`opt-${l.code}`}
                value={labels[l.code] ?? ''}
                maxLength={80}
                onChange={(e) => {
                  setLabels({ ...labels, [l.code]: e.target.value })
                  if (!option && l.isDefault) setCode(codeFrom(e.target.value))
                }}
              />
            </Field>
          ))}
          {!option && (
            <Field id="opt-code" label="Código">
              <Input id="opt-code" value={code} className="font-mono" onChange={(e) => setCode(e.target.value)} />
            </Field>
          )}
          {catalog === 'unit' && (
            <>
              <Field id="opt-sell" label="Se cuenta en">
                <NativeSelect id="opt-sell" value={sell} onChange={(e) => setSell(e.target.value)}>
                  <option value="meter">Metros (se vende por corte o rollo)</option>
                  <option value="piece">Piezas (se cuenta de uno en uno)</option>
                </NativeSelect>
              </Field>
              {locales.map((l) => (
                <Field key={l.code} id={`opt-pl-${l.code}`} label={`Plural (${l.code})`}>
                  <Input id={`opt-pl-${l.code}`} value={plural[l.code] ?? ''} onChange={(e) => setPlural({ ...plural, [l.code]: e.target.value })} placeholder="metros" />
                </Field>
              ))}
              <div className="space-y-1 text-sm">
                <label className="flex items-center gap-2">
                  <input type="checkbox" className="size-4" checked={base} onChange={(e) => setBase(e.target.checked)} /> Puede ser unidad base
                </label>
                <label className="flex items-center gap-2">
                  <input type="checkbox" className="size-4" checked={presentation} onChange={(e) => setPresentation(e.target.checked)} /> Puede ser presentación
                </label>
                <label className="flex items-center gap-2">
                  <input type="checkbox" className="size-4" checked={roll} onChange={(e) => setRoll(e.target.checked)} /> Es un rollo (activa la venta por rollo completo)
                </label>
              </div>
            </>
          )}
          {(catalog === 'weight_unit' || catalog === 'length_unit') && (
            <Field id="opt-factor" label={catalog === 'weight_unit' ? 'Gramos que vale una unidad' : 'Milímetros que vale una unidad'}>
              <Input id="opt-factor" inputMode="decimal" value={factor} onChange={(e) => setFactor(e.target.value)} />
            </Field>
          )}
          <Field id="opt-pos" label="Posición en la lista (menor = primero)">
            <Input id="opt-pos" inputMode="numeric" className="w-28" value={position} onChange={(e) => setPosition(e.target.value)} />
          </Field>
          <Actions onClose={onClose} disabled={!valid} />
        </form>
      </DialogContent>
    </Dialog>
  )
}
