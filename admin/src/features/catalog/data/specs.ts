// Pure helpers of the product data sheet: the three states of a field and how a form turns into what the API stores.

export type FieldState = 'value' | 'pending' | 'not_applicable'

export interface FieldDef {
  key: string
  label: string
  kind: string
  scope: string
  group: string
  catalog?: string | null
}

export interface Measure {
  value: string
  unit: string
}

export type Values = Record<string, unknown>
export type States = Record<string, FieldState>

export const isEmptyValue = (v: unknown): boolean => {
  if (v === null || v === undefined || v === '') return true
  if (Array.isArray(v)) return v.length === 0
  if (typeof v === 'object' && 'value' in (v as object)) {
    const m = (v as Measure).value
    return m === null || m === undefined || String(m).trim() === ''
  }
  return false
}

/** A value always wins; an empty field is "does not apply" only if someone said so, otherwise pending. */
export const stateOf = (key: string, values: Values, states: States): FieldState => {
  if (!isEmptyValue(values[key])) return 'value'
  return states[key] === 'not_applicable' ? 'not_applicable' : 'pending'
}

export const fromSheet = (fields: { key: string; state: string; value: unknown }[]): { values: Values; states: States } => {
  const values: Values = {}
  const states: States = {}
  for (const f of fields) {
    if (f.value !== null && f.value !== undefined) values[f.key] = f.value
    states[f.key] = f.state as FieldState
  }
  return { values, states }
}

/** What to send: an empty field clears the stored value; the state says what that emptiness means. */
export const toPayload = (keys: string[], values: Values, states: States): { specs: Record<string, unknown>; states: Record<string, string> } => {
  const specs: Record<string, unknown> = {}
  const out: Record<string, string> = {}
  for (const k of keys) {
    const v = values[k]
    specs[k] = isEmptyValue(v) ? null : v
    out[k] = stateOf(k, values, states) === 'not_applicable' ? 'not_applicable' : 'pending'
  }
  return { specs, states: out }
}

/** Required fields nobody has filled in or ruled out. */
export const missingRequired = (required: string[], values: Values, states: States): string[] => required.filter((k) => stateOf(k, values, states) === 'pending')

export const labelOf = (labels: { locale: string; value: string | null }[], locale: string): string => labels.find((l) => l.locale === locale)?.value ?? labels[0]?.value ?? ''

/** Letters, digits and underscores from a name: the code a new list entry gets. */
export const codeFrom = (text: string): string =>
  text
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 40)
