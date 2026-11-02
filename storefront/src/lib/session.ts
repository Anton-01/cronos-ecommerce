import 'server-only'

import { cookies } from 'next/headers'

export const YEAR = 60 * 60 * 24 * 365
const base = { httpOnly: true, sameSite: 'lax' as const, secure: process.env.NODE_ENV === 'production', path: '/' }

export interface CartRef {
  id: string
  token: string | null
  market: string
}

export async function readCart(): Promise<CartRef | null> {
  const raw = (await cookies()).get('cart')?.value
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as CartRef
    return typeof parsed.id === 'string' ? parsed : null
  } catch {
    return null
  }
}

export async function writeCart(ref: CartRef): Promise<void> {
  ;(await cookies()).set('cart', JSON.stringify(ref), { ...base, maxAge: YEAR })
}

export async function clearCart(): Promise<void> {
  ;(await cookies()).delete('cart')
}

export async function readMarket(): Promise<string | null> {
  return (await cookies()).get('market')?.value ?? null
}

/** The store the visitor chose. Readable by scripts on purpose: it is a preference, not a secret. */
export async function writeMarket(code: string): Promise<void> {
  ;(await cookies()).set('market', code, { path: '/', sameSite: 'lax', maxAge: YEAR })
}

/** The visitor answered the "you are in another store" notice for the country they are in: do not ask again until that country changes. */
export async function writeMarketAnswered(): Promise<void> {
  const jar = await cookies()
  const geo = jar.get('geo')?.value
  if (geo) jar.set('market_ok', geo, { path: '/', sameSite: 'lax', maxAge: YEAR })
}

export async function readToken(): Promise<string | null> {
  return (await cookies()).get('session')?.value ?? null
}

export async function writeToken(token: string, expiresAt: string | null): Promise<void> {
  const maxAge = expiresAt ? Math.max(60, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000)) : 60 * 60 * 24 * 7
  ;(await cookies()).set('session', token, { ...base, maxAge })
}

export async function clearToken(): Promise<void> {
  ;(await cookies()).delete('session')
}
