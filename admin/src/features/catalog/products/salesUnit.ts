/**
 * Client-side mirror of the server's sales rules, for instant feedback while editing.
 * The API stays authoritative. Quantities have at most 3 decimals and money 2, so the
 * arithmetic is done in integers (thousandths / cents): no floating-point drift.
 */
export interface SalesForm {
  unit: 'PIECE' | 'METER'
  step: string
  min: string
  max: string
  sellByCut: boolean
  sellByRoll: boolean
  rollLength: string
  rollPrice: string
}

export const PIECES: SalesForm = { unit: 'PIECE', step: '1', min: '1', max: '', sellByCut: true, sellByRoll: false, rollLength: '', rollPrice: '' }

export const RIBBON: SalesForm = { unit: 'METER', step: '0.5', min: '1', max: '', sellByCut: true, sellByRoll: false, rollLength: '', rollPrice: '' }

/** "2.5" -> 2500 (thousandths). NaN when the text is not a plain non-negative decimal with up to 3 decimals. */
export function toMilli(text: string): number {
  const t = text.trim()
  if (!/^\d{1,9}(\.\d{1,3})?$/.test(t)) return Number.NaN
  const [whole = '0', frac = ''] = t.split('.')
  return Number(whole) * 1000 + Number(frac.padEnd(3, '0'))
}

/** "18.5" -> 1850 cents. */
export function toCents(text: string): number {
  const t = text.trim()
  if (!/^\d{1,12}(\.\d{1,2})?$/.test(t)) return Number.NaN
  const [whole = '0', frac = ''] = t.split('.')
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'))
}

export function formatCents(cents: number): string {
  return `$${(cents / 100).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

export function formatMilli(milli: number): string {
  return String(milli / 1000)
}

/** Human-readable problems with the configuration (empty list = valid). */
export function validateSales(s: SalesForm): string[] {
  const problems: string[] = []
  const step = toMilli(s.step)
  const min = toMilli(s.min)
  const max = s.max.trim() === '' ? null : toMilli(s.max)

  if (!(step > 0)) problems.push('El paso debe ser un número mayor que cero.')
  if (!(min > 0)) problems.push('El mínimo debe ser un número mayor que cero.')
  if (step > 0 && min > 0) {
    if (min < step) problems.push('El mínimo no puede ser menor que el paso.')
    else if (min % step !== 0) problems.push('El mínimo debe ser múltiplo del paso.')
  }
  if (max !== null) {
    if (Number.isNaN(max)) problems.push('El máximo no es un número válido.')
    else if (min > 0 && max < min) problems.push('El máximo no puede ser menor que el mínimo.')
    else if (step > 0 && max % step !== 0) problems.push('El máximo debe ser múltiplo del paso.')
  }

  if (s.unit === 'PIECE') {
    if (step !== 1000) problems.push('Las piezas se venden de una en una (paso 1).')
    if (min > 0 && min % 1000 !== 0) problems.push('Las piezas son números enteros.')
    return problems
  }

  if (!s.sellByCut && !s.sellByRoll) problems.push('Activa la venta por corte, por rollo o ambas.')
  if (s.sellByRoll && !(toMilli(s.rollLength) > 0)) problems.push('Indica cuántos metros tiene un rollo.')
  if (s.rollPrice.trim() !== '' && Number.isNaN(toCents(s.rollPrice))) problems.push('El precio del rollo no es válido.')

  return problems
}

/** One-line summary a shop owner can read back to confirm the configuration. */
export function describeSales(s: SalesForm): string[] {
  if (s.unit === 'PIECE') return ['Se vende por pieza.']

  const lines: string[] = []
  if (s.sellByCut) {
    const max = s.max.trim() === '' ? 'sin tope' : `hasta ${s.max} m`
    lines.push(`Corte libre: desde ${s.min} m, ${max}, en pasos de ${s.step} m.`)
  }
  if (s.sellByRoll) lines.push(`Rollo completo de ${s.rollLength || '?'} m${s.rollPrice.trim() ? ` a ${formatCents(toCents(s.rollPrice))} por rollo` : ' (precio por metro × largo)'}.`)
  return lines
}

/** Worked examples shown next to the price so mistakes are obvious before saving. */
export function priceExamples(s: SalesForm, unitPrice: string): string[] {
  const price = toCents(unitPrice)
  if (s.unit !== 'METER' || Number.isNaN(price) || validateSales(s).length > 0) return []

  const out: string[] = []
  if (s.sellByCut) {
    const q = toMilli(s.min) + 2 * toMilli(s.step)
    // cents * thousandths / 1000, rounded half up like the server
    out.push(`${formatMilli(q)} m de corte = ${formatCents(Math.round((price * q) / 1000))}`)
  }
  if (s.sellByRoll) {
    const length = toMilli(s.rollLength)
    const roll = s.rollPrice.trim() !== '' ? toCents(s.rollPrice) : Math.round((price * length) / 1000)
    out.push(`1 rollo (${formatMilli(length)} m) = ${formatCents(roll)}`)
    if (s.rollPrice.trim() !== '') {
      const cutEquivalent = Math.round((price * length) / 1000)
      const saving = cutEquivalent - roll
      if (saving > 0) out.push(`Ahorro por rollo frente al corte: ${formatCents(saving)}`)
      else if (saving < 0) out.push('⚠ El rollo cuesta más que comprar la misma longitud por corte.')
    }
  }
  return out
}

/** Maps the editor state to the API input. Empty strings become null (not sent). */
export function toSalesInput(s: SalesForm) {
  const blank = (v: string) => (v.trim() === '' ? null : v.trim())
  return {
    unit: s.unit,
    step: blank(s.step),
    min: blank(s.min),
    max: blank(s.max),
    sellByCut: s.unit === 'PIECE' ? true : s.sellByCut,
    sellByRoll: s.unit === 'PIECE' ? false : s.sellByRoll,
    rollLength: s.unit === 'METER' && s.sellByRoll ? blank(s.rollLength) : null,
    rollPrice: s.unit === 'METER' && s.sellByRoll ? blank(s.rollPrice) : null,
  }
}

export function fromSalesApi(sales: {
  unit: string
  step: string
  min: string
  max?: string | null
  sellByCut: boolean
  sellByRoll: boolean
  rollLength?: string | null
  rollPrice?: string | null
}): SalesForm {
  const trim = (v: string | null | undefined) => (v == null ? '' : String(Number(v)))
  return {
    unit: sales.unit === 'METER' ? 'METER' : 'PIECE',
    step: trim(sales.step),
    min: trim(sales.min),
    max: trim(sales.max),
    sellByCut: sales.sellByCut,
    sellByRoll: sales.sellByRoll,
    rollLength: trim(sales.rollLength),
    rollPrice: sales.rollPrice ?? '',
  }
}
