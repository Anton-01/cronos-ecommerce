'use server'

import { cookies } from 'next/headers'

import { GraphQLFailure, gql } from '@/lib/gql'

export interface NewsletterState { status?: 'check_email' | 'confirmed' | 'unsubscribed'; error?: 'email' | 'human' | 'rate' | 'invalid' | 'expired' | 'unexpected' }

const text = (f: FormData, k: string) => String(f.get(k) ?? '')

function fail(e: unknown): NewsletterState {
  if (e instanceof GraphQLFailure) {
    if (e.code === 'INVALID_EMAIL') return { error: 'email' }
    if (e.code === 'HUMAN_CHECK_FAILED') return { error: 'human' }
    if (e.code === 'RATE_LIMITED') return { error: 'rate' }
    if (e.code === 'INVALID_TOKEN') return { error: 'invalid' }
    if (e.code === 'EXPIRED_TOKEN') return { error: 'expired' }
  }
  return { error: 'unexpected' }
}

/** Footer sign-up. The API answers "check your e-mail" for every valid address, so this form cannot be used to learn who is on the list. */
export async function subscribeNewsletter(_: NewsletterState, form: FormData): Promise<NewsletterState> {
  try {
    await gql(
      `mutation($email: String!, $token: String, $market: String, $locale: String, $website: String, $rendered: String) { subscribeToNewsletter(email: $email, humanToken: $token, source: "footer", market: $market, locale: $locale, website: $website, renderedAt: $rendered) { status } }`,
      { email: text(form, 'email'), token: text(form, 'cf-turnstile-response') || null, market: (await cookies()).get('market')?.value ?? null, locale: text(form, 'locale') || null, website: text(form, 'website') || null, rendered: text(form, 'renderedAt') || null },
    )
    return { status: 'check_email' }
  } catch (e) {
    return fail(e)
  }
}

/** Confirm / unsubscribe are buttons (POST), not plain links: mail scanners that open every link would otherwise confirm or unsubscribe people by themselves. */
export async function confirmNewsletter(token: string): Promise<NewsletterState> {
  try {
    await gql(`mutation($token: String!) { confirmNewsletter(token: $token) { status } }`, { token })
    return { status: 'confirmed' }
  } catch (e) {
    return fail(e)
  }
}

export async function unsubscribeNewsletter(token: string): Promise<NewsletterState> {
  try {
    await gql(`mutation($token: String!) { unsubscribeNewsletter(token: $token) { status } }`, { token })
    return { status: 'unsubscribed' }
  } catch (e) {
    return fail(e)
  }
}
