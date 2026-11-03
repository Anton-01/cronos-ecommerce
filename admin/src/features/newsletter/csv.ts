/** One CSV cell: quoted when needed, and a leading = + - @ is neutralised so a spreadsheet never runs an address as a formula. */
export function cell(value: string | null | undefined): string {
  let v = value ?? ''
  if (/^[=+\-@\t\r]/.test(v)) v = `'${v}`
  return /[",\n\r]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v
}

export function toCsv(header: string[], rows: (string | null | undefined)[][]): string {
  return [header, ...rows].map((r) => r.map(cell).join(',')).join('\r\n') + '\r\n'
}
