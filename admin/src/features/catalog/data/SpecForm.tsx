import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

import { NativeSelect } from '../shared'
import { stateOf, labelOf, type FieldDef, type Measure, type States, type Values } from './specs'
import type { Lists } from './useLists'

interface Props {
  fields: FieldDef[]
  values: Values
  states: States
  required: string[]
  lists: Lists
  disabled?: boolean
  onChange: (key: string, value: unknown) => void
  onState: (key: string, state: 'pending' | 'not_applicable') => void
}

/** The fields of a data sheet. Each one can be filled in, left pending ("I don't know yet") or marked as not applicable: nothing has to be invented. */
export function SpecForm({ fields, values, states, required, lists, disabled, onChange, onState }: Props) {
  return (
    <div className="grid gap-x-6 gap-y-5 md:grid-cols-2">
      {fields.map((f) => (
        <Field key={f.key} def={f} {...{ values, states, lists, disabled, onChange, onState }} required={required.includes(f.key)} />
      ))}
    </div>
  )
}

function Field({ def, values, states, required, lists, disabled, onChange, onState }: { def: FieldDef; required: boolean } & Omit<Props, 'fields' | 'required'>) {
  const state = stateOf(def.key, values, states)
  const off = disabled || state === 'not_applicable'
  const id = `spec-${def.key}`
  const value = values[def.key]
  const wide = def.kind === 'colors' || def.kind === 'tags'

  return (
    <div className={cn('space-y-1.5', wide && 'md:col-span-2')}>
      <Label htmlFor={id}>
        {def.label}
        {required && (
          <span className="text-destructive" aria-hidden>
            {' '}
            *
          </span>
        )}
        {required && <span className="sr-only"> (obligatorio)</span>}
      </Label>
      {def.kind === 'text' && <Input id={id} value={(value as string) ?? ''} disabled={off} maxLength={500} onChange={(e) => onChange(def.key, e.target.value)} />}
      {def.kind === 'number' && <Input id={id} inputMode="decimal" value={(value as string) ?? ''} disabled={off} onChange={(e) => onChange(def.key, e.target.value)} />}
      {def.kind === 'option' && <OptionSelect id={id} def={def} value={(value as string) ?? ''} lists={lists} disabled={off} onChange={(v) => onChange(def.key, v)} />}
      {def.kind === 'measure' && <MeasureInput id={id} def={def} value={(value as Measure | undefined) ?? { value: '', unit: '' }} lists={lists} disabled={off} onChange={(m) => onChange(def.key, m)} />}
      {def.kind === 'colors' && <ColorChips id={id} label={def.label} lists={lists} value={(value as string[]) ?? []} disabled={off} onChange={(v) => onChange(def.key, v)} />}
      {def.kind === 'tags' && (
        <Input id={id} value={Array.isArray(value) ? (value as string[]).join(', ') : ''} disabled={off} placeholder="moños, manualidades" onChange={(e) => onChange(def.key, e.target.value.split(',').map((t) => t.replace(/^\s+/, '')))} />
      )}
      <div className="flex flex-wrap items-center gap-2 text-xs">
        <Pill pressed={state === 'pending'} disabled={disabled || state === 'value'} onClick={() => onState(def.key, 'pending')}>
          Dato pendiente
        </Pill>
        <Pill pressed={state === 'not_applicable'} disabled={disabled || state === 'value'} onClick={() => onState(def.key, 'not_applicable')}>
          No aplica
        </Pill>
        <span className="text-muted-foreground">Vacío = pendiente</span>
      </div>
    </div>
  )
}

function Pill({ pressed, children, ...rest }: { pressed: boolean; children: React.ReactNode } & Omit<React.ComponentProps<'button'>, 'children'>) {
  return (
    <button type="button" aria-pressed={pressed} {...rest} className={cn('rounded-full border px-3 py-1 transition-colors disabled:opacity-50', pressed ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-accent')}>
      {children}
    </button>
  )
}

function OptionSelect({ id, def, value, lists, disabled, onChange }: { id: string; def: FieldDef; value: string; lists: Lists; disabled?: boolean; onChange: (v: string) => void }) {
  const base = def.key === 'base_unit'
  const presentation = def.key === 'presentation_unit'
  const options = (lists.byCatalog[def.catalog ?? ''] ?? []).filter((o) => {
    const m = o.meta as { base?: boolean; presentation?: boolean } | null
    return base ? m?.base : presentation ? m?.presentation : true
  })
  return (
    <NativeSelect id={id} value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)}>
      <option value="">{presentation ? 'Sin presentación' : 'Sin elegir'}</option>
      {options.map((o) => (
        <option key={o.code} value={o.code}>
          {lists.label(o, base)}
        </option>
      ))}
    </NativeSelect>
  )
}

function MeasureInput({ id, def, value, lists, disabled, onChange }: { id: string; def: FieldDef; value: Measure; lists: Lists; disabled?: boolean; onChange: (m: Measure) => void }) {
  const units = lists.byCatalog[def.catalog ?? ''] ?? []
  const unit = value.unit || units[0]?.code || ''
  return (
    <div className="flex gap-2">
      <Input id={id} inputMode="decimal" value={value.value ?? ''} disabled={disabled} onChange={(e) => onChange({ value: e.target.value, unit })} />
      <NativeSelect aria-label={`Unidad de ${def.label}`} className="w-24 shrink-0" value={unit} disabled={disabled} onChange={(e) => onChange({ value: value.value ?? '', unit: e.target.value })}>
        {units.map((u) => (
          <option key={u.code} value={u.code}>
            {lists.label(u)}
          </option>
        ))}
      </NativeSelect>
    </div>
  )
}

/** The colour list as chips: a person sees the colour, not an id. */
export function ColorChips({ id, lists, value, disabled, onChange, label = 'Colores' }: { id?: string; lists: Lists; value: string[]; disabled?: boolean; onChange: (v: string[]) => void; label?: string }) {
  return (
    <div id={id} role="group" aria-label={label} className="flex flex-wrap gap-2">
      {lists.colors.map((c) => {
        const on = value.includes(c.id)
        const hex = (c.meta as { hex?: string } | null)?.hex
        return (
          <button
            key={c.id}
            type="button"
            aria-pressed={on}
            disabled={disabled}
            onClick={() => onChange(on ? value.filter((x) => x !== c.id) : [...value, c.id])}
            className={cn('inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-sm transition-colors disabled:opacity-50', on ? 'border-primary bg-primary/10 font-medium' : 'hover:bg-accent')}
          >
            <span className="size-3 rounded-full border" style={{ background: hex ?? 'transparent' }} aria-hidden />
            {labelOf(c.labels, lists.defaultLocale)}
          </button>
        )
      })}
    </div>
  )
}
