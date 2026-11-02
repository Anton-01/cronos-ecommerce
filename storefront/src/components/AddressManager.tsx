'use client'

import { useRouter } from 'next/navigation'
import { useState, useTransition } from 'react'

import { makeDefaultAddress, removeAddress } from '@/app/actions/addresses'
import type { SavedAddress } from '@/lib/addresses'
import type { Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

import { AddressForm } from './AddressForm'

export function AddressManager({ addresses, locale, countries }: { addresses: SavedAddress[]; locale: Locale; countries: string[] }) {
  const t = messages(locale)
  const router = useRouter()
  const [editing, setEditing] = useState<SavedAddress | 'new' | null>(null)
  const [pending, start] = useTransition()
  const done = () => { setEditing(null); router.refresh() }
  const run = (fn: () => Promise<unknown>) => start(async () => { await fn(); router.refresh() })

  return (
    <div className="space-y-4" aria-busy={pending}>
      {editing ? (
        <AddressForm locale={locale} countries={countries} address={editing === 'new' ? undefined : editing} onSaved={done} onCancel={() => setEditing(null)} />
      ) : (
        <button type="button" className="btn btn-primary" onClick={() => setEditing('new')}>{t.addAddress}</button>
      )}
      {addresses.length === 0 && !editing && <p>{t.noAddresses}</p>}
      <ul className="grid gap-4 md:grid-cols-2">
        {addresses.map((a) => (
          <li key={a.id} className="card space-y-2 p-5 text-sm">
            <div className="flex items-center justify-between gap-2">
              <strong style={{ color: 'var(--c-heading)' }}>{a.label || a.recipientName}</strong>
              <span className="flex gap-1.5">
                <span className="rounded-full bg-surface-alt px-2 py-0.5 text-xs">{a.type === 'BILLING' ? t.billingKind : t.shippingKind}</span>
                {a.isDefault && <span className="rounded-full bg-primary-soft px-2 py-0.5 text-xs">{t.defaultAddress}</span>}
              </span>
            </div>
            <address className="not-italic">{a.recipientName}<br />{a.street} {a.exteriorNo}{a.interiorNo ? ` int. ${a.interiorNo}` : ''}<br />{a.neighborhood ? `${a.neighborhood}, ` : ''}{a.city}, {a.state} {a.zip}<br />{a.countryCode}{a.phone ? ` · ${a.phone}` : ''}{a.taxId ? <><br />{a.taxId} {a.businessName}</> : null}</address>
            <div className="flex flex-wrap gap-3 pt-1">
              <button type="button" className="underline" onClick={() => setEditing(a)}>{t.editAddress}</button>
              {!a.isDefault && <button type="button" className="underline" disabled={pending} onClick={() => run(() => makeDefaultAddress(a.id))}>{t.setDefault}</button>}
              <button type="button" className="underline text-danger" disabled={pending} onClick={() => { if (window.confirm(t.confirmDelete)) run(() => removeAddress(a.id)) }}>{t.deleteAddress}</button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
