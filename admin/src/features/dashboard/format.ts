/** Money for display. The server sends exact decimal strings; Intl only formats them. */
export function money(amount: string, currency: string, locale = 'es-MX'): string {
  const n = Number(amount)
  if (!Number.isFinite(n)) return amount
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).format(n)
  } catch {
    return `${n.toFixed(2)} ${currency}`
  }
}

/** "+12.5 %" / "−3 %" / "—" when there was nothing to compare with. */
export function change(value: number | null | undefined): { text: string; tone: 'up' | 'down' | 'flat' | 'none' } {
  if (value === null || value === undefined) return { text: '—', tone: 'none' }
  if (value === 0) return { text: '0 %', tone: 'flat' }
  const text = `${value > 0 ? '+' : '−'}${Math.abs(value).toLocaleString('es-MX', { maximumFractionDigits: 1 })} %`
  return { text, tone: value > 0 ? 'up' : 'down' }
}

/** Series per market, ready to draw: heights relative to the biggest day so a bar chart reads at a glance. */
export function bars(rows: { day: string; revenue: string; orders: number }[]): { day: string; revenue: number; orders: number; height: number }[] {
  const max = Math.max(0, ...rows.map((r) => Number(r.revenue)))
  return rows.map((r) => ({ day: r.day, revenue: Number(r.revenue), orders: r.orders, height: max > 0 ? Math.max(Number(r.revenue) > 0 ? 4 : 0, Math.round((Number(r.revenue) / max) * 100)) : 0 }))
}

export const ATTENTION_LABEL: Record<string, { text: string; to: string }> = {
  checkouts_need_review: { text: 'Checkouts que requieren revisión (dinero sin conciliar)', to: '/payments' },
  payments_with_anomaly: { text: 'Pagos con anomalía', to: '/payments' },
  payments_disputed: { text: 'Pagos en disputa', to: '/payments' },
  orders_disputed: { text: 'Pedidos con pago en disputa', to: '/orders' },
  orders_cancelled_not_refunded: { text: 'Pedidos cancelados con pago sin reembolsar', to: '/orders' },
  webhooks_stuck: { text: 'Notificaciones de pago fallidas o agotadas', to: '/payments' },
  notifications_failed: { text: 'Correos que no se pudieron enviar', to: '/orders' },
  outbox_stuck: { text: 'Eventos de pago sin entregar', to: '/payments' },
  orders_unshipped_3d: { text: 'Pedidos pagados sin enviar desde hace más de 3 días', to: '/orders' },
  custom_requests_waiting: { text: 'Solicitudes personalizadas sin atender (más de 24 h)', to: '/custom-orders' },
  quotes_expiring_3d: { text: 'Cotizaciones que vencen en 3 días', to: '/custom-orders' },
  low_stock_products: { text: 'Productos con stock bajo', to: '/inventory' },
}
