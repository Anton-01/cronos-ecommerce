'use server'

import { cookies } from 'next/headers'

import { GraphQLFailure, gql } from '@/lib/gql'

export interface ContactState { ok?: boolean; reference?: string; error?: 'human' | 'rate' | 'unexpected'; fields?: Record<string, string[]>; values?: Record<string, string> }

const text = (f: FormData, k: string) => String(f.get(k) ?? '')

/**
 * The browser sends the form to this action (never straight to the API); here the visitor's real IP is added, which the API needs both for the
 * Cloudflare check and for its per-address limit. The values come back on a refusal so the person does not have to retype them.
 */
export async function sendContact(_: ContactState, form: FormData): Promise<ContactState> {
  const values = Object.fromEntries(['name', 'email', 'phone', 'topic', 'orderNumber', 'message'].map((k) => [k, text(form, k)]))
  const market = (await cookies()).get('market')?.value ?? null
  try {
    const data = await gql<{ submitContactMessage: { reference: string } }>(
      `mutation($input: ContactMessageInput!, $token: String, $market: String, $locale: String) { submitContactMessage(input: $input, humanToken: $token, market: $market, locale: $locale) { reference } }`,
      {
        input: { name: values.name, email: values.email, phone: values.phone || null, topic: values.topic || 'general', orderNumber: values.orderNumber || null, message: values.message, website: text(form, 'website') || null, renderedAt: text(form, 'renderedAt') || null },
        token: text(form, 'cf-turnstile-response') || null,
        market,
        locale: text(form, 'locale') || null,
      },
    )
    return { ok: true, reference: data.submitContactMessage.reference }
  } catch (e) {
    if (e instanceof GraphQLFailure) {
      if (e.code === 'HUMAN_CHECK_FAILED') return { error: 'human', values }
      if (e.code === 'RATE_LIMITED') return { error: 'rate', values }
      if (e.code === 'INVALID_MESSAGE') return { fields: e.fields, values }
    }
    return { error: 'unexpected', values }
  }
}
