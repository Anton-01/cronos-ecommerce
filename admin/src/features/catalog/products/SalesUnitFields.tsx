import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

import { NativeSelect } from '../shared'
import { PIECES, RIBBON, describeSales, priceExamples, validateSales, type SalesForm } from './salesUnit'

/** Pieces, or metres sold by free cut, by whole roll, or both, with live validation and worked price examples. */
export function SalesUnitFields({ value, onChange, price, disabled }: { value: SalesForm; onChange: (next: SalesForm) => void; price: string; disabled?: boolean }) {
  const set = (patch: Partial<SalesForm>) => onChange({ ...value, ...patch })
  const problems = validateSales(value)
  const meter = value.unit === 'METER'

  return (
    <div className="space-y-4">
      <div className="max-w-xs space-y-1.5">
        <Label htmlFor="sales-unit">Se vende por</Label>
        <NativeSelect id="sales-unit" value={value.unit} disabled={disabled} onChange={(e) => onChange(e.target.value === 'METER' ? RIBBON : PIECES)}>
          <option value="PIECE">Pieza</option>
          <option value="METER">Metro (listones y cintas)</option>
        </NativeSelect>
      </div>

      {meter && (
        <>
          <fieldset className="space-y-3 rounded-lg border p-3" disabled={disabled}>
            <legend className="px-1 text-sm font-medium">Formas de compra</legend>
            <div className="flex items-center gap-2">
              <Checkbox id="sell-cut" checked={value.sellByCut} onCheckedChange={(v) => set({ sellByCut: v === true })} />
              <Label htmlFor="sell-cut">Corte libre (el cliente elige cuántos metros)</Label>
            </div>
            {value.sellByCut && (
              <div className="grid gap-3 pl-6 sm:grid-cols-3">
                <Field id="sales-step" label="Paso (m)" hint="Múltiplos de…" value={value.step} onChange={(step) => set({ step })} />
                <Field id="sales-min" label="Mínimo (m)" value={value.min} onChange={(min) => set({ min })} />
                <Field id="sales-max" label="Máximo (m)" hint="Vacío = sin tope" value={value.max} onChange={(max) => set({ max })} />
              </div>
            )}
            <div className="flex items-center gap-2">
              <Checkbox id="sell-roll" checked={value.sellByRoll} onCheckedChange={(v) => set({ sellByRoll: v === true })} />
              <Label htmlFor="sell-roll">Rollo completo</Label>
            </div>
            {value.sellByRoll && (
              <div className="grid gap-3 pl-6 sm:grid-cols-2">
                <Field id="sales-roll-length" label="Metros por rollo" value={value.rollLength} onChange={(rollLength) => set({ rollLength })} />
                <Field id="sales-roll-price" label="Precio por rollo" hint="Vacío = metros × precio" value={value.rollPrice} onChange={(rollPrice) => set({ rollPrice })} />
              </div>
            )}
          </fieldset>
          {!value.sellByCut && (
            <div className="grid gap-3 sm:grid-cols-3">
              <p className="text-xs text-muted-foreground sm:col-span-3">Solo por rollo: el cliente compra rollos enteros.</p>
            </div>
          )}
        </>
      )}

      {problems.length > 0 ? (
        <ul role="alert" className="list-disc space-y-0.5 rounded-md bg-destructive/10 py-2 pl-7 pr-3 text-sm text-destructive">
          {problems.map((p) => (
            <li key={p}>{p}</li>
          ))}
        </ul>
      ) : (
        <div className="space-y-1 rounded-md bg-muted/60 p-3 text-sm" aria-label="Resumen de venta">
          {describeSales(value).map((line) => (
            <p key={line}>{line}</p>
          ))}
          {priceExamples(value, price).map((line) => (
            <p key={line} className="text-muted-foreground">
              {line}
            </p>
          ))}
        </div>
      )}
    </div>
  )
}

function Field({ id, label, hint, value, onChange }: { id: string; label: string; hint?: string; value: string; onChange: (v: string) => void }) {
  return (
    <div className="space-y-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Input id={id} inputMode="decimal" value={value} onChange={(e) => onChange(e.target.value)} />
      {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  )
}
