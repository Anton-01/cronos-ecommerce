import 'server-only'

import { redirect } from 'next/navigation'
import { cache } from 'react'

import { href, type Locale } from './config'
import { gql } from './gql'
import { readToken } from './session'

export interface Me { id: string; email: string; profile: { firstName: string | null; lastName: string | null; phone: string | null; countryCode: string | null } | null }

/** The signed-in shopper, or null. A token the API no longer accepts counts as signed out. */
export const getMe = cache(async (): Promise<Me | null> => {
  if (!(await readToken())) return null
  try {
    return (await gql<{ me: Me | null }>(`query { me { id email profile { firstName lastName phone countryCode } } }`)).me
  } catch {
    return null
  }
})

export async function requireUser(locale: Locale, next: string): Promise<Me> {
  const me = await getMe()
  if (!me) redirect(`${href.page(locale, 'login')}?next=${encodeURIComponent(next)}`)
  return me
}
