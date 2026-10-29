/** The phrase typed to move a provider to real charges. Accents and case are ignored, like the API does. */
export const GO_LIVE_PHRASE = 'PASAR A PRODUCCION'

export function matchesGoLivePhrase(text: string): boolean {
  const normalise = (v: string) =>
    v
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .replace(/\s+/g, ' ')
      .trim()
      .toUpperCase()
  return normalise(text) === GO_LIVE_PHRASE
}

/** Values left empty keep the stored secret, so only fields the person typed are sent. */
export function changedCredentials(values: Record<string, string>): Record<string, string> {
  return Object.fromEntries(Object.entries(values).filter(([, v]) => v.trim() !== '').map(([k, v]) => [k, v.trim()]))
}

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendiente',
  requires_action: 'Esperando al cliente',
  processing: 'Procesando',
  captured: 'Cobrado',
  partially_refunded: 'Reembolso parcial',
  refunded: 'Reembolsado',
  failed: 'Fallido',
  cancelled: 'Cancelado',
  disputed: 'En disputa',
  received: 'Recibido',
  processed: 'Procesado',
  dead: 'Agotó reintentos',
  ignored: 'Ignorado',
  rejected: 'Rechazado (firma)',
  open: 'Abierto',
  paid: 'Pagado',
  expired: 'Expirado',
  needs_review: 'Requiere revisión',
}

export const statusLabel = (s: string) => STATUS_LABEL[s] ?? s

/** Statuses that need a person to look. */
export const needsAttention = (s: string) => ['failed', 'dead', 'rejected', 'disputed', 'needs_review'].includes(s)
