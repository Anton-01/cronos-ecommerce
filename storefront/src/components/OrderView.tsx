import type { Locale } from '@/lib/config'
import { money, quantity } from '@/lib/format'
import { messages } from '@/lib/i18n'

export interface OrderData {
  number: string; status: string; paymentStatus: string; fulfillmentStatus: string; currency: string; taxIncluded: boolean
  subtotal: string; discount: string; shipping: string; tax: string; total: string; placedAt: string | null
  items: { name: string; sku: string | null; unit: string; quantity: string; unitPrice: string; total: string }[]
  shippingAddress: Record<string, string | null> | null
  timeline: { status: string; note: string | null; at: string }[]
}

export const ORDER_FIELDS = `number status paymentStatus fulfillmentStatus currency taxIncluded subtotal discount shipping tax total placedAt
  items { name sku unit quantity unitPrice total } shippingAddress timeline { status note at }`

const dateFmt = (iso: string, locale: Locale) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso))

export function OrderView({ order, locale }: { order: OrderData; locale: Locale }) {
  const t = messages(locale)
  const a = order.shippingAddress
  return (
    <div className="container-x mt-6 space-y-6">
      <header>
        <h1 className="text-3xl md:text-4xl">{t.order} {order.number}</h1>
        <p className="mt-1 text-sm">{t.status}: <strong style={{ color: 'var(--c-heading)' }}>{order.status}</strong> · {order.paymentStatus} · {order.fulfillmentStatus}{order.placedAt ? ` · ${dateFmt(order.placedAt, locale)}` : ''}</p>
      </header>
      <div className="grid gap-6 lg:grid-cols-[1fr_360px]">
        <section className="card p-5" aria-labelledby="items">
          <h2 id="items" className="mb-3 text-xl">{t.items}</h2>
          <ul className="divide-y" style={{ borderColor: 'var(--c-border)' }}>
            {order.items.map((i, n) => (
              <li key={n} className="flex justify-between gap-4 py-3 text-sm">
                <span>{i.name}<br /><span className="text-xs">{i.sku} · {quantity(i.quantity, locale)} {i.unit}</span></span>
                <span className="font-semibold" style={{ color: 'var(--c-heading)' }}>{money(i.total, order.currency, locale)}</span>
              </li>
            ))}
          </ul>
          <dl className="mt-4 space-y-1 border-t pt-3 text-sm" style={{ borderColor: 'var(--c-border)' }}>
            <div className="flex justify-between"><dt>{t.subtotal}</dt><dd>{money(order.subtotal, order.currency, locale)}</dd></div>
            {Number(order.discount) > 0 && <div className="flex justify-between"><dt>{t.discount}</dt><dd>− {money(order.discount, order.currency, locale)}</dd></div>}
            <div className="flex justify-between"><dt>{t.shipping}</dt><dd>{money(order.shipping, order.currency, locale)}</dd></div>
            <div className="flex justify-between"><dt>{order.taxIncluded ? t.taxIncluded : t.tax}</dt><dd>{money(order.tax, order.currency, locale)}</dd></div>
            <div className="flex justify-between text-base font-semibold" style={{ color: 'var(--c-heading)' }}><dt>{t.total}</dt><dd>{money(order.total, order.currency, locale)}</dd></div>
          </dl>
        </section>
        <div className="space-y-6">
          {a && (
            <section className="card p-5" aria-labelledby="ship">
              <h2 id="ship" className="mb-2 text-xl">{t.shipTo}</h2>
              <address className="text-sm not-italic">{a.recipientName}<br />{a.street} {a.exteriorNo}{a.interiorNo ? ` int. ${a.interiorNo}` : ''}<br />{a.neighborhood ? `${a.neighborhood}, ` : ''}{a.city}, {a.state} {a.zip}<br />{a.countryCode}</address>
            </section>
          )}
          <section className="card p-5" aria-labelledby="tl">
            <h2 id="tl" className="mb-3 text-xl">{t.timeline}</h2>
            <ol className="space-y-3 border-l pl-4 text-sm" style={{ borderColor: 'var(--c-border)' }}>
              {order.timeline.map((e, n) => (
                <li key={n}><strong style={{ color: 'var(--c-heading)' }}>{e.status}</strong><br /><time dateTime={e.at} className="text-xs">{dateFmt(e.at, locale)}</time>{e.note && <p>{e.note}</p>}</li>
              ))}
            </ol>
          </section>
        </div>
      </div>
    </div>
  )
}
