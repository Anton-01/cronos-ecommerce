import { useMutation, useQuery } from '@apollo/client/react'
import { ChevronDown, ChevronRight, CornerDownRight, MoreHorizontal, Plus, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect } from '@/features/catalog/shared'
import { formatQty } from '@/features/inventory/quantity'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { WhCreateLocationDocument, WhLocationsDocument, WhMoveLocationDocument, WhUpdateLocationDocument, type WhLocationsQuery } from '@/graphql/generated'

import { LocationSelect } from './LocationSelect'
import { useWarehouse } from './useWarehouse'

type Loc = WhLocationsQuery['whLocations'][number]

const TYPES: Record<string, string> = { zone: 'Zona', aisle: 'Pasillo', shelf: 'Estante', level: 'Nivel', bin: 'Caja', receiving: 'Recepción', other: 'Otro' }

type Dialogs = { kind: 'create'; parent: Loc | null } | { kind: 'edit'; loc: Loc } | { kind: 'move'; loc: Loc } | null

/** The shelves of the warehouse as a tree: search, show inactive, expand/collapse, add a child, edit or re-parent. Codes never change. */
export function LocationsPage() {
  const { current } = useWarehouse()
  const { can } = useAuth()
  const manage = can('warehouse.locations')
  const [showInactive, setShowInactive] = useState(false)
  const [search, setSearch] = useState('')
  const [collapsed, setCollapsed] = useState<Set<string>>(new Set())
  const [dialog, setDialog] = useState<Dialogs>(null)
  const { data, loading, refetch } = useQuery(WhLocationsDocument, { variables: { warehouseId: current?.id ?? '', includeInactive: showInactive }, skip: !current, fetchPolicy: 'cache-and-network' })
  const all = useMemo(() => data?.whLocations ?? [], [data])

  const children = useMemo(() => {
    const m = new Map<string | null, Loc[]>()
    for (const l of all) m.set(l.parentId, [...(m.get(l.parentId) ?? []), l])
    return m
  }, [all])

  const term = search.trim().toLowerCase()
  // A match keeps its ancestors visible so the result still shows where it is.
  const visible = useMemo(() => {
    if (!term) return null
    const keep = new Set<string>()
    const byId = new Map(all.map((l) => [l.id, l]))
    for (const l of all) {
      if (l.name.toLowerCase().includes(term) || l.code.toLowerCase().includes(term)) {
        for (let p: Loc | undefined = l; p; p = p.parentId ? byId.get(p.parentId) : undefined) keep.add(p.id)
      }
    }
    return keep
  }, [all, term])

  const toggle = (id: string) =>
    setCollapsed((s) => {
      const n = new Set(s)
      if (n.has(id)) n.delete(id)
      else n.add(id)
      return n
    })
  const rows: { loc: Loc; hasChildren: boolean }[] = []
  const walk = (parent: string | null) => {
    for (const l of children.get(parent) ?? []) {
      if (visible && !visible.has(l.id)) continue
      const kids = children.get(l.id) ?? []
      rows.push({ loc: l, hasChildren: kids.length > 0 })
      if (kids.length > 0 && (visible || !collapsed.has(l.id))) walk(l.id)
    }
  }
  walk(null)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-56 flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
          <label htmlFor="loc-search" className="sr-only">
            Buscar ubicaciones
          </label>
          <Input id="loc-search" className="h-11 pl-9" value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Buscar ubicaciones…" />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="size-4" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} /> Mostrar inactivas
        </label>
        <Button variant="outline" size="sm" onClick={() => setCollapsed(new Set())}>
          Expandir todo
        </Button>
        <Button variant="outline" size="sm" onClick={() => setCollapsed(new Set(all.filter((l) => (children.get(l.id) ?? []).length > 0).map((l) => l.id)))}>
          Contraer
        </Button>
        {manage && (
          <Button onClick={() => setDialog({ kind: 'create', parent: null })}>
            <Plus className="size-4" /> Nueva ubicación principal
          </Button>
        )}
      </div>

      {loading && !data && <Skeleton className="h-48 w-full" />}
      <ul className="divide-y rounded-xl border bg-card" aria-label="Ubicaciones del almacén">
        {rows.map(({ loc, hasChildren }) => (
          <li key={loc.id} className="flex min-h-14 items-center gap-2 py-2 pr-2" style={{ paddingLeft: `${0.5 + (loc.depth - 1) * 1.5}rem` }}>
            {hasChildren ? (
              <button type="button" onClick={() => toggle(loc.id)} className="rounded p-2 hover:bg-accent" aria-label={`${collapsed.has(loc.id) ? 'Expandir' : 'Contraer'} ${loc.name}`} aria-expanded={!collapsed.has(loc.id)}>
                {collapsed.has(loc.id) ? <ChevronRight className="size-4" /> : <ChevronDown className="size-4" />}
              </button>
            ) : (
              <CornerDownRight className="mx-2 size-4 text-muted-foreground" aria-hidden />
            )}
            <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
              <Link to={`/warehouse/shelf/${encodeURIComponent(loc.code)}`} className="font-medium underline-offset-2 hover:underline">
                {loc.name}
              </Link>
              <span className="font-mono text-xs text-muted-foreground">{loc.code}</span>
              <Badge variant="secondary">{TYPES[loc.type] ?? loc.type}</Badge>
              <Badge variant="outline">Nivel {loc.depth}</Badge>
              {!loc.isActive && <Badge variant="destructive">Inactiva</Badge>}
              {!loc.isPickable && loc.isActive && <Badge variant="outline">No se surte de aquí</Badge>}
            </div>
            {loc.products > 0 && (
              <Badge variant="default" className="bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200" title={`${formatQty(loc.units)} unidades`}>
                {loc.products} SKU con existencia
              </Badge>
            )}
            {manage && (
              <>
                {loc.isActive && (
                  <Button variant="ghost" size="icon" aria-label={`Agregar dentro de ${loc.name}`} onClick={() => setDialog({ kind: 'create', parent: loc })}>
                    <Plus className="size-4" />
                  </Button>
                )}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" aria-label={`Más acciones de ${loc.name}`}>
                      <MoreHorizontal className="size-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end">
                    <DropdownMenuItem onSelect={() => setDialog({ kind: 'edit', loc })}>Editar</DropdownMenuItem>
                    {!loc.isSystem && <DropdownMenuItem onSelect={() => setDialog({ kind: 'move', loc })}>Mover a otro lugar</DropdownMenuItem>}
                    <DropdownMenuItem asChild>
                      <Link to={`/warehouse/labels?shelf=${encodeURIComponent(loc.code)}`}>Imprimir etiqueta QR</Link>
                    </DropdownMenuItem>
                  </DropdownMenuContent>
                </DropdownMenu>
              </>
            )}
          </li>
        ))}
        {!loading && rows.length === 0 && <li className="p-6 text-center text-sm text-muted-foreground">{term ? `Nada con «${search}».` : 'Todavía no hay ubicaciones.'}</li>}
      </ul>

      {current && dialog && <LocationDialog key={dialog.kind + ('loc' in dialog ? dialog.loc.id : dialog.parent?.id ?? 'root')} dialog={dialog} warehouseId={current.id} onClose={() => setDialog(null)} onDone={() => void refetch()} />}
    </div>
  )
}

function LocationDialog({ dialog, warehouseId, onClose, onDone }: { dialog: NonNullable<Dialogs>; warehouseId: string; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const [create] = useMutation(WhCreateLocationDocument)
  const [update] = useMutation(WhUpdateLocationDocument)
  const [move] = useMutation(WhMoveLocationDocument)
  const editing = dialog.kind === 'edit' ? dialog.loc : null
  const [code, setCode] = useState('')
  const [name, setName] = useState(editing?.name ?? '')
  const [type, setType] = useState(editing?.type ?? 'shelf')
  const [capacity, setCapacity] = useState(editing?.capacity ?? '')
  const [pickable, setPickable] = useState(editing?.isPickable ?? true)
  const [active, setActive] = useState(editing?.isActive ?? true)
  const [parent, setParent] = useState(dialog.kind === 'move' ? (dialog.loc.parentId ?? '') : '')

  const title = dialog.kind === 'create' ? (dialog.parent ? `Nueva ubicación dentro de ${dialog.parent.name}` : 'Nueva ubicación principal') : dialog.kind === 'edit' ? `Editar ${dialog.loc.name}` : `Mover ${dialog.loc.name}`

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const done = (ok: boolean) => ok && (onDone(), onClose())
    if (dialog.kind === 'create') void run(() => create({ variables: { warehouseId, input: { parentId: dialog.parent?.id ?? null, code: code.trim(), name: name.trim(), type, capacity: capacity.trim() || null, isPickable: pickable } } }), 'Ubicación creada').then(done)
    if (dialog.kind === 'edit') void run(() => update({ variables: { id: dialog.loc.id, input: { name: name.trim(), type: dialog.loc.isSystem ? undefined : type, capacity: capacity.trim() || null, isPickable: pickable, isActive: active } } }), 'Ubicación actualizada').then(done)
    if (dialog.kind === 'move') void run(() => move({ variables: { id: dialog.loc.id, parentId: parent || null } }), 'Ubicación movida').then(done)
  }

  return (
    <Dialog open onOpenChange={(o) => !o && onClose()}>
      <DialogContent>
        <form onSubmit={submit} className="space-y-4">
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>
              {dialog.kind === 'move' ? 'Se mueve con todo lo que tiene debajo. Las existencias se quedan donde están.' : 'El código es lo que va en la etiqueta del anaquel y nunca cambia.'}
            </DialogDescription>
          </DialogHeader>
          {dialog.kind === 'move' ? (
            <Field id="loc-parent" label="Nuevo lugar">
              <LocationSelect id="loc-parent" warehouseId={warehouseId} value={parent} onChange={setParent} exclude={dialog.loc.id} allowEmpty emptyLabel="Al nivel principal" />
            </Field>
          ) : (
            <>
              {dialog.kind === 'create' && (
                <Field id="loc-code" label="Código (ej. E03, C02)">
                  <Input id="loc-code" value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} maxLength={24} className="font-mono uppercase" required />
                </Field>
              )}
              <Field id="loc-name" label="Nombre">
                <Input id="loc-name" value={name} onChange={(e) => setName(e.target.value)} maxLength={120} required />
              </Field>
              {!editing?.isSystem && (
                <Field id="loc-type" label="Tipo">
                  <NativeSelect id="loc-type" value={type} onChange={(e) => setType(e.target.value)}>
                    {Object.entries(TYPES).filter(([k]) => k !== 'receiving').map(([k, v]) => (
                      <option key={k} value={k}>
                        {v}
                      </option>
                    ))}
                  </NativeSelect>
                </Field>
              )}
              <Field id="loc-cap" label="Capacidad (opcional)">
                <Input id="loc-cap" inputMode="decimal" value={capacity} onChange={(e) => setCapacity(e.target.value)} />
              </Field>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" className="size-4" checked={pickable} onChange={(e) => setPickable(e.target.checked)} /> Se surten pedidos desde aquí
              </label>
              {editing && !editing.isSystem && (
                <label className="flex items-center gap-2 text-sm">
                  <input type="checkbox" className="size-4" checked={active} onChange={(e) => setActive(e.target.checked)} /> Activa (para desactivar debe estar vacía)
                </label>
              )}
            </>
          )}
          <Actions onClose={onClose} disabled={dialog.kind === 'create' ? code.trim() === '' || name.trim() === '' : dialog.kind === 'edit' ? name.trim() === '' : false} />
        </form>
      </DialogContent>
    </Dialog>
  )
}
