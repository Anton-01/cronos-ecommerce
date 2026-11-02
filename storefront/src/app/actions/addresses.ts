'use server'

import { revalidatePath } from 'next/cache'

import { ADDRESS_FIELDS, type SavedAddress } from '@/lib/addresses'
import { GraphQLFailure, gql } from '@/lib/gql'

export interface AddressInput { type?: 'SHIPPING' | 'BILLING'; label?: string; recipientName: string; phone?: string; street: string; exteriorNo?: string; interiorNo?: string; neighborhood?: string; city: string; state: string; countryCode: string; zip: string; references?: string; taxId?: string; businessName?: string }
export interface AddressResult { ok: boolean; address?: SavedAddress; message?: string; code?: string | null; fields?: Record<string, string[]> }

const fail = (e: unknown): AddressResult => (e instanceof GraphQLFailure ? { ok: false, message: e.message, code: e.code, fields: e.fields } : { ok: false, message: 'unexpected' })

/** Empty strings are not sent: the API stores "nothing" as null, never as an empty text. */
const clean = (i: AddressInput): AddressInput => Object.fromEntries(Object.entries(i).filter(([, v]) => v !== '' && v !== undefined)) as unknown as AddressInput

export async function saveAddress(input: AddressInput, id?: string): Promise<AddressResult> {
  try {
    const data = id
      ? await gql<{ updateAddress: SavedAddress }>(`mutation($id: ID!, $input: SavedAddressInput!) { updateAddress(id: $id, input: $input) { ${ADDRESS_FIELDS} } }`, { id, input: clean(input) })
      : await gql<{ addAddress: SavedAddress }>(`mutation($input: SavedAddressInput!) { addAddress(input: $input) { ${ADDRESS_FIELDS} } }`, { input: clean(input) })
    revalidatePath('/', 'layout')
    return { ok: true, address: 'updateAddress' in data ? data.updateAddress : data.addAddress }
  } catch (e) {
    return fail(e)
  }
}

export async function removeAddress(id: string): Promise<AddressResult> {
  try {
    await gql(`mutation($id: ID!) { deleteAddress(id: $id) }`, { id })
    revalidatePath('/', 'layout')
    return { ok: true }
  } catch (e) {
    return fail(e)
  }
}

export async function makeDefaultAddress(id: string): Promise<AddressResult> {
  try {
    await gql(`mutation($id: ID!) { setDefaultAddress(id: $id) { id } }`, { id })
    revalidatePath('/', 'layout')
    return { ok: true }
  } catch (e) {
    return fail(e)
  }
}
