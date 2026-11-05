import { useMutation } from '@apollo/client/react'
import { useRef, useState } from 'react'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { useRun } from '@/features/catalog/products/useRun'
import { Field } from '@/features/pricing/CurrenciesPage'
import { isValidCount, isValidQty } from '@/features/inventory/quantity'
import { WhMoveDocument, WhReceiveDocument, WhRequestAdjustmentDocument, WhReturnToShelfDocument, WhWriteOffDocument } from '@/graphql/generated'

import { LocationSelect } from './LocationSelect'
import { newKey } from './lib'

export type ActionKind = 'receive' | 'move' | 'writeoff' | 'adjust' | 'return'

export interface ActionTarget {
  kind: ActionKind
  productId: string
  productLabel: string
  warehouseId: string
  /** The shelf the action starts from (move, write-off, adjust, return). */
  place?: { locationId: string; code: string; quantity: string; version: number }
}

const TITLE: Record<ActionKind, string> = {
  receive: 'Recibir mercancía',
  move: 'Mover a otra ubicación',
  writeoff: 'Dar de baja (merma)',
  adjust: 'Corregir cantidad',
  return: 'Regresar a un anaquel',
}

/** One dialog for every shelf action. The idempotency key lives as long as the dialog attempt, so a double tap changes nothing twice. */
export function ActionDialog({ target, onClose, onDone }: { target: ActionTarget | null; onClose: () => void; onDone: () => void }) {
  return (
    <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent>{target && <ActionForm key={`${target.kind}-${target.place?.locationId ?? 'x'}`} target={target} onClose={onClose} onDone={onDone} />}</DialogContent>
    </Dialog>
  )
}

function ActionForm({ target, onClose, onDone }: { target: ActionTarget; onClose: () => void; onDone: () => void }) {
  const run = useRun()
  const key = useRef(newKey())
  const [qty, setQty] = useState('')
  const [to, setTo] = useState('')
  const [text, setText] = useState('')
  const [cost, setCost] = useState('')
  const [busy, setBusy] = useState(false)
  const [receive] = useMutation(WhReceiveDocument)
  const [move] = useMutation(WhMoveDocument)
  const [writeOff] = useMutation(WhWriteOffDocument)
  const [adjust] = useMutation(WhRequestAdjustmentDocument)
  const [back] = useMutation(WhReturnToShelfDocument)
  const { kind, productId, warehouseId, place } = target

  const valid =
    kind === 'adjust' ? isValidCount(qty) && text.trim() !== '' : isValidQty(qty) && (kind !== 'move' || to !== '') && (kind !== 'writeoff' || text.trim() !== '') && (kind !== 'return' || to !== '')

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!valid || busy) return
    setBusy(true)
    const k = key.current
    let ok = false
    if (kind === 'receive') ok = await run(() => receive({ variables: { input: { productId, warehouseId, quantity: qty.trim(), locationId: to || null, unitCost: cost.trim() || null, note: text.trim() || null, idempotencyKey: k } } }), 'Recepción registrada')
    if (kind === 'move') ok = await run(() => move({ variables: { input: { productId, fromLocationId: place!.locationId, toLocationId: to, quantity: qty.trim(), expectedVersion: place!.version, note: text.trim() || null, idempotencyKey: k } } }), 'Movimiento registrado')
    if (kind === 'writeoff') ok = await run(() => writeOff({ variables: { input: { productId, locationId: place!.locationId, quantity: qty.trim(), reason: text.trim(), expectedVersion: place!.version, idempotencyKey: k } } }), 'Baja registrada')
    if (kind === 'return') ok = await run(() => back({ variables: { input: { productId, locationId: to, quantity: qty.trim(), note: text.trim() || null, idempotencyKey: k } } }), 'Devolución al anaquel registrada')
    if (kind === 'adjust') {
      let status = ''
      ok = await run(async () => {
        const r = await adjust({ variables: { input: { productId, locationId: place!.locationId, targetQuantity: qty.trim(), seenVersion: place!.version, reason: text.trim(), idempotencyKey: k } } })
        status = r.data?.whRequestAdjustment.status ?? ''
      }, 'Corrección enviada')
      if (ok && status === 'pending') {
        // the toast above said "Listo"; be precise about what happened
        const { toast } = await import('sonner')
        toast.info('Tu corrección es grande: quedó pendiente de aprobación por una persona con permiso.')
      }
    }
    setBusy(false)
    if (ok) {
      onDone()
      onClose()
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4" noValidate>
      <DialogHeader>
        <DialogTitle>{TITLE[kind]}</DialogTitle>
        <DialogDescription>
          {target.productLabel}
          {place && ` · ${place.code} (hay ${Number(place.quantity).toLocaleString('es-MX', { maximumFractionDigits: 3 })})`}
        </DialogDescription>
      </DialogHeader>

      <Field id="wh-qty" label={kind === 'adjust' ? 'Cantidad real que hay ahí' : 'Cantidad'}>
        <Input id="wh-qty" inputMode="decimal" autoComplete="off" autoFocus value={qty} onChange={(e) => setQty(e.target.value)} className="h-12 text-lg" aria-invalid={qty !== '' && !(kind === 'adjust' ? isValidCount(qty) : isValidQty(qty))} />
      </Field>

      {(kind === 'receive' || kind === 'move' || kind === 'return') && (
        <Field id="wh-to" label={kind === 'move' ? 'A dónde va' : kind === 'return' ? 'En qué anaquel queda' : 'Dónde se acomoda'}>
          <LocationSelect id="wh-to" warehouseId={warehouseId} value={to} onChange={setTo} exclude={place?.locationId} allowEmpty={kind === 'receive'} hideReception={kind === 'receive'} />
        </Field>
      )}
      {kind === 'receive' && (
        <Field id="wh-cost" label="Costo por unidad (opcional)">
          <Input id="wh-cost" inputMode="decimal" value={cost} onChange={(e) => setCost(e.target.value)} />
        </Field>
      )}
      <Field id="wh-text" label={kind === 'writeoff' ? 'Motivo (obligatorio)' : kind === 'adjust' ? 'Qué encontraste (obligatorio)' : 'Nota (opcional)'}>
        <Textarea id="wh-text" rows={2} value={text} maxLength={500} onChange={(e) => setText(e.target.value)} />
      </Field>

      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" size="lg" disabled={!valid || busy}>
          {busy ? 'Guardando…' : 'Confirmar'}
        </Button>
      </div>
    </form>
  )
}
