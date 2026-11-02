'use server'

import { redirect } from 'next/navigation'

import { openGuestCartIntoAccount } from '@/lib/cart'
import { LOCALES, href, localeFromPrefix } from '@/lib/config'
import { GraphQLFailure, gql } from '@/lib/gql'
import { clearToken, readMarket, writeToken } from '@/lib/session'

export interface FormState { error?: string; fields?: Record<string, string[]> }

const USER = 'token expiresAt'

function fail(e: unknown): FormState {
  if (e instanceof GraphQLFailure) return { error: e.code === 'INVALID_CREDENTIALS' ? 'invalid' : e.message, fields: e.fields }
  return { error: 'unexpected' }
}

/** Only ever send the shopper somewhere on this site (an open redirect after login is a classic phishing aid). */
function safeNext(next: FormDataEntryValue | null, fallback: string): string {
  const value = typeof next === 'string' ? next : ''
  return value.startsWith('/') && !value.startsWith('//') && !value.includes('\\') ? value : fallback
}

export async function login(_: FormState, form: FormData): Promise<FormState> {
  const locale = localeFromPrefix(String(form.get('locale'))) ?? 'es-MX'
  try {
    const data = await gql<{ login: { token: string; expiresAt: string | null } }>(`mutation($input: LoginInput!) { login(input: $input) { ${USER} } }`, { input: { email: String(form.get('email') ?? ''), password: String(form.get('password') ?? '') } })
    await writeToken(data.login.token, data.login.expiresAt)
    await openGuestCartIntoAccount(await readMarket())
  } catch (e) {
    return fail(e)
  }
  redirect(safeNext(form.get('next'), href.page(locale, 'account')))
}

export async function register(_: FormState, form: FormData): Promise<FormState> {
  const rawLocale = localeFromPrefix(String(form.get('locale'))) ?? 'es-MX'
  try {
    const data = await gql<{ register: { token: string; expiresAt: string | null } }>(
      `mutation($input: RegisterInput!) { register(input: $input) { ${USER} } }`,
      { input: { email: String(form.get('email') ?? ''), password: String(form.get('password') ?? ''), firstName: String(form.get('firstName') ?? '') || null, lastName: String(form.get('lastName') ?? '') || null, locale: rawLocale } },
    )
    await writeToken(data.register.token, data.register.expiresAt)
    await openGuestCartIntoAccount(await readMarket())
  } catch (e) {
    return fail(e)
  }
  redirect(safeNext(form.get('next'), href.page(rawLocale, 'account')))
}

export async function logout(locale: string): Promise<void> {
  try {
    await gql(`mutation { logout }`)
  } catch {
    // The token is dropped from the browser either way.
  }
  await clearToken()
  redirect(`/${LOCALES[localeFromPrefix(locale) ?? 'es-MX'].prefix}`)
}
