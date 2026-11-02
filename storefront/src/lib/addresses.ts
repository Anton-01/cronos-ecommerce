import 'server-only'

import { gql } from './gql'

export interface SavedAddress {
  id: string; type: 'SHIPPING' | 'BILLING'; label: string | null; recipientName: string; phone: string | null; street: string; exteriorNo: string | null; interiorNo: string | null
  neighborhood: string | null; city: string; state: string; countryCode: string; zip: string; references: string | null; taxId: string | null; businessName: string | null; isDefault: boolean
}

export const ADDRESS_FIELDS = 'id type label recipientName phone street exteriorNo interiorNo neighborhood city state countryCode zip references taxId businessName isDefault'

/** The signed-in shopper's address book. The API serves it from its own cache (dropped only when they change an address). */
export async function getAddresses(): Promise<SavedAddress[]> {
  try {
    return (await gql<{ myAddresses: SavedAddress[] }>(`query { myAddresses { ${ADDRESS_FIELDS} } }`)).myAddresses
  } catch {
    return []
  }
}
