/** "mx, us ,MX" → ["MX", "US"]: two-letter codes, upper-cased, no repeats. Anything else is reported back, not guessed at. */
export function parseCountries(text: string): { countries: string[]; invalid: string[] } {
  const parts = text.split(/[\s,;]+/).map((p) => p.trim().toUpperCase()).filter(Boolean)
  const invalid = [...new Set(parts.filter((p) => !/^[A-Z]{2}$/.test(p)))]
  const countries = [...new Set(parts.filter((p) => /^[A-Z]{2}$/.test(p)))]
  return { countries, invalid }
}

/** The label customary for a seller's tax id in a country, used as a hint while the profile is empty. */
export function taxIdHint(country: string): string {
  if (country === 'MX') return 'RFC: 12 caracteres (persona moral) o 13 (persona física)'
  if (country === 'US') return 'EIN: 9 dígitos (NN-NNNNNNN)'
  return 'Identificador fiscal: 4 a 30 caracteres'
}

const FLAGS: Record<string, string> = { MX: 'México', US: 'Estados Unidos' }
export const countryName = (code: string) => FLAGS[code] ?? code

/** What the admin sees about everything a store keeps separate; helps spot a store that cannot sell yet. */
export function readiness(p: { legalName: string | null; taxId: string | null; warehouses: { isActive: boolean }[]; paymentProviders: string[]; taxRateCount: number }): string[] {
  const missing: string[] = []
  if (!p.legalName || !p.taxId) missing.push('datos fiscales')
  if (!p.warehouses.some((w) => w.isActive)) missing.push('almacén activo')
  if (p.paymentProviders.length === 0) missing.push('método de pago')
  if (p.taxRateCount === 0) missing.push('tasa de impuesto')
  return missing
}
