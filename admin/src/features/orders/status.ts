const ORDER: Record<string, string> = {
  pending: 'Pendiente de pago',
  processing: 'En preparación',
  shipped: 'Enviado',
  delivered: 'Entregado',
  completed: 'Completado',
  cancelled: 'Cancelado',
  refunded: 'Reembolsado',
}

const PAYMENT: Record<string, string> = {
  unpaid: 'Sin pagar',
  paid: 'Pagado',
  partially_refunded: 'Reembolso parcial',
  refunded: 'Reembolsado',
  disputed: 'En disputa',
}

const FULFILLMENT: Record<string, string> = { unfulfilled: 'Sin enviar', partial: 'Envío parcial', shipped: 'Enviado', delivered: 'Entregado' }

const SHIPMENT: Record<string, string> = {
  pending: 'Pendiente',
  in_transit: 'En camino',
  exception: 'Incidencia',
  out_for_delivery: 'En reparto',
  delivered: 'Entregado',
  returned: 'Devuelto',
  cancelled: 'Cancelado',
}

const CUSTOM: Record<string, string> = {
  submitted: 'Nueva',
  in_review: 'En revisión',
  quoted: 'Cotizada',
  rejected: 'Rechazada',
  converted: 'Convertida en pedido',
  expired: 'Vencida',
  draft: 'Borrador',
  sent: 'Enviada',
  accepted: 'Aceptada',
  superseded: 'Reemplazada',
}

export const orderLabel = (s: string) => ORDER[s] ?? s
export const paymentLabel = (s: string) => PAYMENT[s] ?? s
export const fulfillmentLabel = (s: string) => FULFILLMENT[s] ?? s
export const shipmentLabel = (s: string) => SHIPMENT[s] ?? s
export const customLabel = (s: string) => CUSTOM[s] ?? s

/** Orders and payments that need a person to look. */
export const orderNeedsAttention = (o: { status: string; paymentStatus: string }) => o.paymentStatus === 'disputed' || (o.status === 'cancelled' && o.paymentStatus === 'paid')

/** Moves the operator can make by hand: the API lists them, this only words them. */
export const MOVE_LABEL: Record<string, string> = { processing: 'Marcar en preparación', shipped: 'Marcar enviado', delivered: 'Marcar entregado', completed: 'Completar', refunded: 'Marcar reembolsado' }

/** Quote line total preview while the operator types (the API recalculates everything; this is only a helper). */
export function lineAmount(quantity: string, unitPrice: string): number {
  const q = Number(quantity)
  const p = Number(unitPrice)
  return Number.isFinite(q) && Number.isFinite(p) && q > 0 && p >= 0 ? Math.round(q * p * 100) / 100 : 0
}

export function validQuoteLine(l: { description: string; quantity: string; unitPrice: string }): boolean {
  return l.description.trim() !== '' && Number(l.quantity) > 0 && l.unitPrice.trim() !== '' && Number(l.unitPrice) >= 0
}
