/** "12.500" -> "12.5", "40.000" -> "40": stock is stored with three decimals but shown without trailing zeros. */
export const formatQty = (value: string | null | undefined): string => {
  if (value === null || value === undefined || value === '') return '—'
  const n = Number(value)
  return Number.isFinite(n) ? n.toLocaleString('es-MX', { maximumFractionDigits: 3 }) : value
}

/** True for a positive amount with at most three decimals, the shape the Kardex accepts. */
export const isValidQty = (value: string): boolean => /^\d+(\.\d{1,3})?$/.test(value.trim()) && Number(value) > 0

/** Non-negative, for counts (zero is a valid count). */
export const isValidCount = (value: string): boolean => /^\d+(\.\d{1,3})?$/.test(value.trim())
