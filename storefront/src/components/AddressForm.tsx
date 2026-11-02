'use client'

import { useState, useTransition } from 'react'

import { saveAddress, type AddressInput } from '@/app/actions/addresses'
import type { SavedAddress } from '@/lib/addresses'
import type { Locale } from '@/lib/config'
import { messages } from '@/lib/i18n'

const blank: AddressInput = { type: 'SHIPPING', label: '', recipientName: '', phone: '', street: '', exteriorNo: '', interiorNo: '', neighborhood: '', city: '', state: '', countryCode: '', zip: '', references: '', taxId: '', businessName: '' }

const fromSaved = (a: SavedAddress): AddressInput => ({ type: a.type, label: a.label ?? '', recipientName: a.recipientName, phone: a.phone ?? '', street: a.street, exteriorNo: a.exteriorNo ?? '', interiorNo: a.interiorNo ?? '', neighborhood: a.neighborhood ?? '', city: a.city, state: a.state, countryCode: a.countryCode, zip: a.zip, references: a.references ?? '', taxId: a.taxId ?? '', businessName: a.businessName ?? '' })

/** Add or edit a saved address. The API says which fields are wrong; they are marked here, next to what the shopper typed. */
export function AddressForm({ locale, countries, address, onSaved, onCancel }: { locale: Locale; countries: string[]; address?: SavedAddress; onSaved: () => void; onCancel: () => void }) {
  const t = messages(locale)
  const [v, setV] = useState<AddressInput>(address ? fromSaved(address) : { ...blank, countryCode: countries[0] ?? '' })
  const [error, setError] = useState<string | null>(null)
  const [fields, setFields] = useState<Record<string, string[]>>({})
  const [pending, start] = useTransition()
  const set = (k: keyof AddressInput) => (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => setV({ ...v, [k]: e.target.value })
  const err = (k: string) => fields[`input.${k}`]?.[0] ?? fields[k]?.[0] ?? fields[k.replace(/[A-Z]/g, (c) => `_${c.toLowerCase()}`)]?.[0]

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    start(async () => {
      const r = await saveAddress(v, address?.id)
      if (r.ok) return onSaved()
      setFields(r.fields ?? {})
      setError(r.code === 'ADDRESS_LIMIT' ? r.message ?? t.error : r.message === 'unexpected' ? t.error : Object.keys(r.fields ?? {}).length ? null : (r.message ?? t.error))
    })
  }

  const field = (k: keyof AddressInput, label: string, extra: React.InputHTMLAttributes<HTMLInputElement> = {}) => (
    <label className="text-sm">{label}
      <input className="field mt-1" value={(v[k] as string) ?? ''} onChange={set(k)} aria-invalid={Boolean(err(k))} {...extra} />
      {err(k) && <span role="alert" className="text-xs text-danger">{err(k)}</span>}
    </label>
  )

  return (
    <form onSubmit={submit} className="card space-y-4 p-5" aria-label={address ? t.editAddress : t.newAddress}>
      <h2 className="text-xl">{address ? t.editAddress : t.newAddress}</h2>
      <div className="grid gap-3 sm:grid-cols-2">
        {!address && (
          <label className="text-sm">{t.store === 'Tienda' ? 'Tipo' : 'Kind'}
            <select className="field mt-1" value={v.type} onChange={set('type')}><option value="SHIPPING">{t.shippingKind}</option><option value="BILLING">{t.billingKind}</option></select>
          </label>
        )}
        {field('label', t.addressLabel)}
        {field('recipientName', t.recipient, { required: true, autoComplete: 'name' })}
        {field('phone', t.phone, { type: 'tel', autoComplete: 'tel' })}
        {field('street', t.street, { required: true, autoComplete: 'address-line1' })}
        {field('exteriorNo', t.exteriorNo)}
        {field('interiorNo', t.interiorNo)}
        {field('neighborhood', t.neighborhood)}
        {field('city', t.city, { required: true })}
        {field('state', t.state, { required: true })}
        {field('zip', t.zip, { required: true, autoComplete: 'postal-code' })}
        <label className="text-sm">{t.country}
          <select required className="field mt-1" value={v.countryCode} onChange={set('countryCode')}>{[...new Set([...countries, v.countryCode].filter(Boolean))].map((c) => <option key={c} value={c}>{c}</option>)}</select>
        </label>
        {(address?.type ?? v.type) === 'BILLING' && <>{field('taxId', t.taxId)}{field('businessName', t.businessName)}</>}
      </div>
      {error && <p role="alert" className="text-sm text-danger">{error}</p>}
      <div className="flex gap-2">
        <button className="btn btn-primary" disabled={pending}>{t.saveAddress}</button>
        <button type="button" className="btn btn-ghost" onClick={onCancel}>{t.cancel}</button>
      </div>
    </form>
  )
}
