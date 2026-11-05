// Pure helpers of the warehouse screens (no React): codes, QR payloads, CSV, labels of movement types.

const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

const check = (body: string): string => {
  let sum = 0
  for (let i = 0; i < body.length; i++) sum += ALPHABET.indexOf(body[i]!) * (i + 1)
  return ALPHABET[sum % 32]!
}

/**
 * Same rules as the server's StockCode: tolerant of case, hyphen, spaces and look-alikes (I, L → 1; O → 0), and the check
 * character catches a mistyped code before the server is asked. Returns null when it is not a valid code.
 */
export function normalizeCode(input: string): string | null {
  const t = input.trim().toUpperCase().replace(/[-\s_]/g, '').replace(/[IL]/g, '1').replace(/O/g, '0')
  if (t.length !== 11 || [...t].some((c) => !ALPHABET.includes(c))) return null
  return check(t.slice(0, 10)) === t[10] ? t : null
}

/** What a camera or a scanner hands over: the QR's URL (`https://app/w/<code>`) or just the code. */
export function parseScanned(text: string): { kind: 'item'; code: string } | { kind: 'location'; code: string } | null {
  const loc = /\/w\/loc\/([A-Za-z0-9-]{1,24})(?:[/?#]|$)/.exec(text.trim())
  if (loc) return { kind: 'location', code: loc[1]!.toUpperCase() }
  const url = /\/w\/([A-Za-z0-9-]{11,13})(?:[/?#]|$)/.exec(text.trim())
  const code = normalizeCode(url ? url[1]! : text)
  return code ? { kind: 'item', code } : null
}

/** `7K3M9Q2XA4-6`: how it is printed under the QR. */
export const formatCode = (code: string): string => (code.length === 11 ? `${code.slice(0, 10)}-${code.slice(10)}` : code)

/** The text inside the QR. Only the code: no quantities, names or prices, so a photo of a label reveals nothing. */
export const itemQrPayload = (origin: string, code: string): string => `${origin.replace(/\/$/, '')}/w/${code}`
export const locationQrPayload = (origin: string, code: string): string => `${origin.replace(/\/$/, '')}/w/loc/${code}`

/** A fresh key per attempt at an action: the same tap sent twice (bad signal, double click) changes the shelf once. */
export const newKey = (): string => (typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`)

const csvCell = (value: unknown): string => {
  let s = value === null || value === undefined ? '' : String(value)
  // A cell that starts like a formula would run when the file is opened in a spreadsheet.
  if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
}

export function toCsv<T>(rows: T[], columns: { header: string; value: (row: T) => unknown }[]): string {
  const lines = [columns.map((c) => csvCell(c.header)).join(','), ...rows.map((r) => columns.map((c) => csvCell(c.value(r))).join(','))]
  return `${'\uFEFF'}${lines.join('\r\n')}\r\n` // BOM so Excel reads the accents
}

export const MOVEMENT_LABEL: Record<string, string> = {
  INITIAL: 'Saldo inicial',
  RECEIVE: 'Recepción',
  MOVE_IN: 'Entrada por traslado',
  MOVE_OUT: 'Salida por traslado',
  PICK: 'Surtido',
  ADJUST: 'Ajuste',
  COUNT_ADJUST: 'Ajuste por conteo',
  RETURN: 'Devolución',
  WASTE: 'Merma',
  COMPENSATION: 'Corrección',
}

export const STATUS_LABEL: Record<string, string> = { draft: 'Borrador', published: 'Publicado', inactive: 'Inactivo' }

/** "+5" / "−5" with the sign always visible, so an entry and an exit never look alike. */
export const signed = (value: string): string => {
  const n = Number(value)
  if (!Number.isFinite(n)) return value
  const text = Math.abs(n).toLocaleString('es-MX', { maximumFractionDigits: 3 })
  return n > 0 ? `+${text}` : n < 0 ? `−${text}` : text
}

export function download(filename: string, content: Blob | string, type = 'text/plain'): void {
  const blob = content instanceof Blob ? content : new Blob([content], { type })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  a.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}
