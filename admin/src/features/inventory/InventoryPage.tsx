import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { useRun } from '@/features/catalog/products/useRun'
import { NativeSelect, PageHeader } from '@/features/catalog/shared'
import { Actions, Field } from '@/features/pricing/CurrenciesPage'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'
import {
  AdminInventoryHealthDocument,
  AdminKardexDocument,
  AdminMarketsDocument,
  AdminStockDocument,
  AdminWarehousesDocument,
  CompensateMovementDocument,
  CreateWarehouseDocument,
  DeleteWarehouseDocument,
  RecordMovementDocument,
  SetStockCountDocument,
  TransferStockDocument,
  UpdateWarehouseDocument,
  type AdminKardexQuery,
  type AdminStockQuery,
  type AdminWarehousesQuery,
  type ManualMovementType,
  type MovementType,
} from '@/graphql/generated'
import { useClock } from '@/lib/clock'

import { formatQty, isValidCount, isValidQty } from './quantity'

type StockRow = AdminStockQuery['adminStock']['rows'][number]
type Entry = AdminKardexQuery['adminKardex']['entries'][number]
type Warehouse = AdminWarehousesQuery['adminWarehouses'][number]

const PAGE = 25

const TYPE_LABEL: Record<string, string> = {
  PURCHASE: 'Compra',
  SALE: 'Venta',
  RETURN: 'Devolución',
  ADJUSTMENT: 'Ajuste',
  WASTE: 'Merma',
  TRANSFER_IN: 'Traslado (entrada)',
  TRANSFER_OUT: 'Traslado (salida)',
  RESERVATION_CONSUME: 'Venta (reserva)',
}

export function InventoryPage() {
  const { can } = useAuth()
  const run = useRun()
  const [checking, setChecking] = useState(false)
  const { refetch } = useQuery(AdminInventoryHealthDocument, { skip: true })

  const verify = async () => {
    setChecking(true)
    try {
      const { data } = await refetch()
      const h = data?.adminInventoryHealth
      if (h?.ok) toast.success('Inventario íntegro: los niveles coinciden con el Kardex y las cadenas de firmas están completas.')
      else toast.error(`Diferencias encontradas: ${[...(h?.brokenChains ?? []), ...(h?.levelMismatches ?? []), ...(h?.reservedMismatches ?? []), ...(h?.productMismatches ?? [])].slice(0, 3).join(' · ')}`)
    } catch {
      await run(() => Promise.reject(new Error('Internal')), '')
    } finally {
      setChecking(false)
    }
  }

  return (
    <div className="max-w-5xl space-y-4">
      <PageHeader
        title="Inventario"
        description="Cada cambio de existencias es un renglón del Kardex que no se edita ni se borra; los errores se corrigen con un movimiento compensatorio."
        actions={
          can('inventory.manage') && (
            <Button variant="outline" disabled={checking} onClick={() => void verify()}>
              Verificar integridad
            </Button>
          )
        }
      />
      <Tabs defaultValue="stock">
        <TabsList>
          <TabsTrigger value="stock">Existencias</TabsTrigger>
          <TabsTrigger value="kardex">Kardex</TabsTrigger>
          <TabsTrigger value="warehouses">Almacenes</TabsTrigger>
        </TabsList>
        <TabsContent value="stock">
          <StockTab />
        </TabsContent>
        <TabsContent value="kardex">
          <KardexTab />
        </TabsContent>
        <TabsContent value="warehouses">
          <WarehousesTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}

function StockTab() {
  const { can } = useAuth()
  const canManage = can('inventory.manage')
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const [warehouseId, setWarehouseId] = useState('')
  const [lowOnly, setLowOnly] = useState(false)
  const [offset, setOffset] = useState(0)
  const { data: warehouses } = useQuery(AdminWarehousesDocument)
  const { data, loading } = useQuery(AdminStockDocument, { variables: { search: debounced || null, warehouseId: warehouseId || null, lowOnly, first: PAGE, offset }, fetchPolicy: 'cache-and-network' })
  const [dialog, setDialog] = useState<{ kind: 'move' | 'count' | 'transfer'; row: StockRow } | null>(null)
  const page = data?.adminStock

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap items-center gap-2">
        <Input className="w-64" type="search" aria-label="Buscar existencias" placeholder="Buscar por nombre o SKU…" value={search} onChange={(e) => { setSearch(e.target.value); setOffset(0) }} />
        <NativeSelect aria-label="Filtrar por almacén" className="w-44" value={warehouseId} onChange={(e) => { setWarehouseId(e.target.value); setOffset(0) }}>
          <option value="">Todos los almacenes</option>
          {warehouses?.adminWarehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code}
            </option>
          ))}
        </NativeSelect>
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={lowOnly} onCheckedChange={(v) => { setLowOnly(v === true); setOffset(0) }} />
          Solo stock bajo
        </label>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.rows.map((r) => (
          <li key={r.productId} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="min-w-0 flex-1">
              <span className="block truncate font-medium">{r.name ?? r.sku}</span>
              <span className="block text-xs text-muted-foreground">
                {r.sku}
                {r.warehouses.length > 0 && ' · ' + r.warehouses.map((w) => `${w.warehouseCode}: ${formatQty(w.onHand)}`).join(' · ')}
              </span>
            </span>
            <span className="w-20 text-right text-sm tabular-nums" title="Existencia física">
              {formatQty(r.onHand)}
            </span>
            <span className="w-20 text-right text-sm tabular-nums text-muted-foreground" title="Apartado en carritos y pedidos">
              {formatQty(r.reserved)}
            </span>
            <span className="w-24 text-right font-semibold tabular-nums" title="Disponible = existencia − apartado">
              {formatQty(r.available)}
            </span>
            {r.low && <Badge variant="destructive">Stock bajo</Badge>}
            {canManage && (
              <span className="flex gap-1">
                <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'move', row: r })}>
                  Movimiento
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'count', row: r })}>
                  Conteo
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setDialog({ kind: 'transfer', row: r })}>
                  Trasladar
                </Button>
              </span>
            )}
          </li>
        ))}
        {page && page.rows.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin productos con control de existencias.</li>}
      </ul>
      {page && page.total > PAGE && (
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>
            {offset + 1}–{Math.min(offset + PAGE, page.total)} de {page.total}
          </span>
          <span className="flex gap-2">
            <Button size="sm" variant="outline" disabled={offset === 0} onClick={() => setOffset(Math.max(0, offset - PAGE))}>
              Anterior
            </Button>
            <Button size="sm" variant="outline" disabled={offset + PAGE >= page.total} onClick={() => setOffset(offset + PAGE)}>
              Siguiente
            </Button>
          </span>
        </div>
      )}
      <Dialog open={dialog !== null} onOpenChange={(o) => !o && setDialog(null)}>
        <DialogContent>
          {dialog?.kind === 'move' && <MovementForm row={dialog.row} warehouses={warehouses?.adminWarehouses ?? []} onClose={() => setDialog(null)} />}
          {dialog?.kind === 'count' && <CountForm row={dialog.row} warehouses={warehouses?.adminWarehouses ?? []} onClose={() => setDialog(null)} />}
          {dialog?.kind === 'transfer' && <TransferForm row={dialog.row} warehouses={warehouses?.adminWarehouses ?? []} onClose={() => setDialog(null)} />}
        </DialogContent>
      </Dialog>
    </div>
  )
}

const REFRESH = ['AdminStock', 'AdminKardex']

function defaultWarehouse(warehouses: Warehouse[]): string {
  return warehouses.find((w) => w.isDefault)?.id ?? warehouses[0]?.id ?? ''
}

function MovementForm({ row, warehouses, onClose }: { row: StockRow; warehouses: Warehouse[]; onClose: () => void }) {
  const run = useRun()
  const [record, { loading }] = useMutation(RecordMovementDocument, { refetchQueries: REFRESH })
  const [form, setForm] = useState({ warehouseId: defaultWarehouse(warehouses), type: 'PURCHASE' as ManualMovementType, quantity: '', increase: true, unitCost: '', note: '' })
  const valid = form.warehouseId && isValidQty(form.quantity) && (form.unitCost === '' || Number(form.unitCost) >= 0)

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(
          () => record({ variables: { input: { productId: row.productId, warehouseId: form.warehouseId, type: form.type, quantity: form.quantity.trim(), increase: form.type === 'ADJUSTMENT' ? form.increase : null, unitCost: form.unitCost.trim() || null, note: form.note.trim() || null } } }),
          'Movimiento registrado',
        ).then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Movimiento · {row.name ?? row.sku}</DialogTitle>
        <DialogDescription>Se agrega al Kardex y no podrá editarse; si te equivocas, compénsalo desde el Kardex.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="mv-type" label="Tipo">
          <NativeSelect id="mv-type" value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value as ManualMovementType })}>
            <option value="PURCHASE">Compra (entrada)</option>
            <option value="RETURN">Devolución (entrada)</option>
            <option value="WASTE">Merma (salida)</option>
            <option value="ADJUSTMENT">Ajuste</option>
          </NativeSelect>
        </Field>
        <Field id="mv-warehouse" label="Almacén">
          <NativeSelect id="mv-warehouse" value={form.warehouseId} onChange={(e) => setForm({ ...form, warehouseId: e.target.value })}>
            {warehouses.filter((w) => w.isActive).map((w) => (
              <option key={w.id} value={w.id}>
                {w.code}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="mv-qty" label="Cantidad (metros o piezas)">
          <Input id="mv-qty" inputMode="decimal" value={form.quantity} onChange={(e) => setForm({ ...form, quantity: e.target.value })} />
        </Field>
        <Field id="mv-cost" label="Costo unitario (opcional)">
          <Input id="mv-cost" inputMode="decimal" value={form.unitCost} onChange={(e) => setForm({ ...form, unitCost: e.target.value })} />
        </Field>
      </div>
      {form.type === 'ADJUSTMENT' && (
        <label className="flex items-center gap-2 text-sm">
          <Checkbox checked={form.increase} onCheckedChange={(v) => setForm({ ...form, increase: v === true })} />
          El ajuste suma existencias (desmarcado: resta)
        </label>
      )}
      <Field id="mv-note" label="Nota (opcional)">
        <Input id="mv-note" maxLength={500} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
      </Field>
      <Actions onClose={onClose} disabled={loading || !valid} label="Registrar" />
    </form>
  )
}

function CountForm({ row, warehouses, onClose }: { row: StockRow; warehouses: Warehouse[]; onClose: () => void }) {
  const run = useRun()
  const [count, { loading }] = useMutation(SetStockCountDocument, { refetchQueries: REFRESH })
  const [warehouseId, setWarehouseId] = useState(defaultWarehouse(warehouses))
  const [counted, setCounted] = useState('')
  const [note, setNote] = useState('')
  const current = row.warehouses.find((w) => w.warehouseId === warehouseId)?.onHand ?? '0'

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(async () => {
          const { data } = await count({ variables: { productId: row.productId, warehouseId, counted: counted.trim(), note: note.trim() || null } })
          if (!data?.setStockCount) toast.info('El conteo coincide con los libros: no hubo diferencia.')
        }, 'Conteo registrado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Conteo físico · {row.name ?? row.sku}</DialogTitle>
        <DialogDescription>Escribe lo que contaste. La diferencia contra los libros se registra como un ajuste; nada se sobrescribe.</DialogDescription>
      </DialogHeader>
      <Field id="ct-warehouse" label="Almacén">
        <NativeSelect id="ct-warehouse" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
          {warehouses.filter((w) => w.isActive).map((w) => (
            <option key={w.id} value={w.id}>
              {w.code}
            </option>
          ))}
        </NativeSelect>
      </Field>
      <p className="text-sm text-muted-foreground">En libros: {formatQty(current)}</p>
      <Field id="ct-counted" label="Cantidad contada">
        <Input id="ct-counted" inputMode="decimal" value={counted} onChange={(e) => setCounted(e.target.value)} />
      </Field>
      <Field id="ct-note" label="Nota (opcional)">
        <Input id="ct-note" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <Actions onClose={onClose} disabled={loading || !warehouseId || !isValidCount(counted)} label="Registrar conteo" />
    </form>
  )
}

function TransferForm({ row, warehouses, onClose }: { row: StockRow; warehouses: Warehouse[]; onClose: () => void }) {
  const run = useRun()
  const [transfer, { loading }] = useMutation(TransferStockDocument, { refetchQueries: REFRESH })
  const active = warehouses.filter((w) => w.isActive)
  const [from, setFrom] = useState(row.warehouses.find((w) => Number(w.available) > 0)?.warehouseId ?? defaultWarehouse(active))
  const [to, setTo] = useState(active.find((w) => w.id !== from)?.id ?? '')
  const [quantity, setQuantity] = useState('')
  const [note, setNote] = useState('')

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => transfer({ variables: { productId: row.productId, fromWarehouseId: from, toWarehouseId: to, quantity: quantity.trim(), note: note.trim() || null } }), 'Traslado registrado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Trasladar · {row.name ?? row.sku}</DialogTitle>
        <DialogDescription>Genera dos movimientos ligados (salida y entrada) o ninguno.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="tr-from" label="Desde">
          <NativeSelect id="tr-from" value={from} onChange={(e) => setFrom(e.target.value)}>
            {active.map((w) => (
              <option key={w.id} value={w.id}>
                {w.code}
              </option>
            ))}
          </NativeSelect>
        </Field>
        <Field id="tr-to" label="Hacia">
          <NativeSelect id="tr-to" value={to} onChange={(e) => setTo(e.target.value)}>
            {active.map((w) => (
              <option key={w.id} value={w.id}>
                {w.code}
              </option>
            ))}
          </NativeSelect>
        </Field>
      </div>
      <Field id="tr-qty" label="Cantidad">
        <Input id="tr-qty" inputMode="decimal" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
      </Field>
      <Field id="tr-note" label="Nota (opcional)">
        <Input id="tr-note" maxLength={500} value={note} onChange={(e) => setNote(e.target.value)} />
      </Field>
      <Actions onClose={onClose} disabled={loading || !from || !to || from === to || !isValidQty(quantity)} label="Trasladar" />
    </form>
  )
}

function KardexTab() {
  const clock = useClock()
  const { can } = useAuth()
  const [type, setType] = useState<MovementType | ''>('')
  const [warehouseId, setWarehouseId] = useState('')
  const { data: warehouses } = useQuery(AdminWarehousesDocument)
  const { data, loading, fetchMore } = useQuery(AdminKardexDocument, { variables: { type: type || null, warehouseId: warehouseId || null }, fetchPolicy: 'cache-and-network' })
  const [compensating, setCompensating] = useState<Entry | null>(null)
  const page = data?.adminKardex

  return (
    <div className="space-y-3 pt-3">
      <div className="flex flex-wrap gap-2">
        <NativeSelect aria-label="Filtrar por tipo" className="w-48" value={type} onChange={(e) => setType(e.target.value as MovementType | '')}>
          <option value="">Todos los tipos</option>
          {Object.entries(TYPE_LABEL).map(([value, label]) => (
            <option key={value} value={value}>
              {label}
            </option>
          ))}
        </NativeSelect>
        <NativeSelect aria-label="Filtrar Kardex por almacén" className="w-44" value={warehouseId} onChange={(e) => setWarehouseId(e.target.value)}>
          <option value="">Todos los almacenes</option>
          {warehouses?.adminWarehouses.map((w) => (
            <option key={w.id} value={w.id}>
              {w.code}
            </option>
          ))}
        </NativeSelect>
      </div>
      {loading && !page && <Skeleton className="h-40 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {page?.entries.map((e) => {
          const entering = Number(e.quantity) > 0
          return (
            <li key={e.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5 text-sm">
              <time dateTime={e.createdAtUtc} title={`UTC: ${e.createdAtUtc}`} className="w-40 tabular-nums">
                {clock.formatDateTime(e.createdAt)}
              </time>
              <Badge variant={entering ? 'success' : 'secondary'}>{TYPE_LABEL[e.type] ?? e.type}</Badge>
              <span className="min-w-0 flex-1 truncate">
                {e.productName ?? e.sku} <span className="text-xs text-muted-foreground">· {e.warehouseCode}</span>
                {e.note && <span className="block truncate text-xs text-muted-foreground">{e.note}</span>}
              </span>
              <span className={`w-20 text-right font-semibold tabular-nums ${entering ? 'text-success' : 'text-destructive'}`}>
                {entering ? '+' : ''}
                {formatQty(e.quantity)}
              </span>
              <span className="w-20 text-right tabular-nums text-muted-foreground" title="Existencia del almacén después del movimiento">
                = {formatQty(e.balanceAfter)}
              </span>
              <span className="text-xs text-muted-foreground" title={e.timezone ?? undefined}>
                {e.userEmail ?? 'sistema'} · {e.country ?? '—'}
              </span>
              {e.compensatedBy && <Badge variant="outline">Compensado</Badge>}
              {can('inventory.manage') && e.referenceType !== 'movement' && !e.compensatedBy && (
                <Button size="sm" variant="ghost" onClick={() => setCompensating(e)}>
                  Compensar
                </Button>
              )}
            </li>
          )
        })}
        {page && page.entries.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin movimientos.</li>}
      </ul>
      {page?.nextBefore && (
        <div className="flex justify-center">
          <Button
            variant="outline"
            onClick={() =>
              void fetchMore({
                variables: { before: page.nextBefore },
                updateQuery: (prev, { fetchMoreResult }) => ({ adminKardex: { ...fetchMoreResult.adminKardex, entries: [...prev.adminKardex.entries, ...fetchMoreResult.adminKardex.entries] } }),
              })
            }
          >
            Cargar más
          </Button>
        </div>
      )}
      <Dialog open={compensating !== null} onOpenChange={(o) => !o && setCompensating(null)}>
        <DialogContent>{compensating && <CompensateForm entry={compensating} onClose={() => setCompensating(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function CompensateForm({ entry, onClose }: { entry: Entry; onClose: () => void }) {
  const run = useRun()
  const [compensate, { loading }] = useMutation(CompensateMovementDocument, { refetchQueries: REFRESH })
  const [reason, setReason] = useState('')

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        void run(() => compensate({ variables: { id: entry.id, reason: reason.trim() } }), 'Movimiento compensado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>Compensar movimiento</DialogTitle>
        <DialogDescription>
          {TYPE_LABEL[entry.type]} de {formatQty(entry.quantity)} en {entry.warehouseCode}. El original se conserva; se agrega un movimiento contrario que lo referencia. Solo se puede compensar una vez.
        </DialogDescription>
      </DialogHeader>
      <Field id="cp-reason" label="Motivo">
        <Input id="cp-reason" maxLength={400} value={reason} onChange={(e) => setReason(e.target.value)} />
      </Field>
      <Actions onClose={onClose} disabled={loading || !reason.trim()} label="Compensar" />
    </form>
  )
}

function WarehousesTab() {
  const { can } = useAuth()
  const canManage = can('inventory.manage')
  const run = useRun()
  const { data, loading } = useQuery(AdminWarehousesDocument)
  const [remove] = useMutation(DeleteWarehouseDocument, { refetchQueries: ['AdminWarehouses'] })
  const [editing, setEditing] = useState<{ warehouse: Warehouse | null } | null>(null)

  return (
    <div className="space-y-3 pt-3">
      {canManage && (
        <div className="flex justify-end">
          <Button onClick={() => setEditing({ warehouse: null })}>
            <Plus /> Nuevo almacén
          </Button>
        </div>
      )}
      {loading && !data && <Skeleton className="h-24 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminWarehouses.map((w) => (
          <li key={w.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
            <span className="w-20 font-mono font-semibold">{w.code}</span>
            <span className="flex-1">
              {w.name} {w.marketCode && <span className="text-muted-foreground">· mercado {w.marketCode}</span>}
            </span>
            {w.isDefault && <Badge>Predeterminado</Badge>}
            {!w.isActive && <Badge variant="outline">Inactivo</Badge>}
            {canManage && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ warehouse: w })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void run(() => remove({ variables: { id: w.id } }), 'Almacén eliminado')}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(o) => !o && setEditing(null)}>
        <DialogContent>{editing && <WarehouseForm key={editing.warehouse?.id ?? 'new'} warehouse={editing.warehouse} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function WarehouseForm({ warehouse, onClose }: { warehouse: Warehouse | null; onClose: () => void }) {
  const run = useRun()
  const { data: markets } = useQuery(AdminMarketsDocument)
  const [create] = useMutation(CreateWarehouseDocument, { refetchQueries: ['AdminWarehouses'] })
  const [update] = useMutation(UpdateWarehouseDocument, { refetchQueries: ['AdminWarehouses'] })
  const [form, setForm] = useState({ code: warehouse?.code ?? '', name: warehouse?.name ?? '', marketCode: warehouse?.marketCode ?? '', isDefault: warehouse?.isDefault ?? false, isActive: warehouse?.isActive ?? true })

  return (
    <form
      className="space-y-4"
      onSubmit={(e) => {
        e.preventDefault()
        const input = { code: form.code.trim(), name: form.name.trim(), marketCode: form.marketCode || null, isDefault: form.isDefault, isActive: form.isActive }
        void run(() => (warehouse ? update({ variables: { id: warehouse.id, input } }) : create({ variables: { input } })), 'Almacén guardado').then((ok) => ok && onClose())
      }}
    >
      <DialogHeader>
        <DialogTitle>{warehouse ? 'Editar almacén' : 'Nuevo almacén'}</DialogTitle>
        <DialogDescription>Los carritos de un mercado apartan primero en el almacén de ese mercado, luego en el predeterminado.</DialogDescription>
      </DialogHeader>
      <div className="grid grid-cols-2 gap-3">
        <Field id="wh-code" label="Código">
          <Input id="wh-code" maxLength={24} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })} />
        </Field>
        <Field id="wh-name" label="Nombre">
          <Input id="wh-name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        </Field>
        <Field id="wh-market" label="Mercado (opcional)">
          <NativeSelect id="wh-market" value={form.marketCode} onChange={(e) => setForm({ ...form, marketCode: e.target.value })}>
            <option value="">Ninguno</option>
            {markets?.adminMarkets.map((m) => (
              <option key={m.id}>{m.code}</option>
            ))}
          </NativeSelect>
        </Field>
      </div>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isDefault} onCheckedChange={(v) => setForm({ ...form, isDefault: v === true })} />
        Almacén predeterminado
      </label>
      <label className="flex items-center gap-2 text-sm">
        <Checkbox checked={form.isActive} onCheckedChange={(v) => setForm({ ...form, isActive: v === true })} />
        Activo
      </label>
      <Actions onClose={onClose} disabled={!form.code.trim() || !form.name.trim()} />
    </form>
  )
}
