'use server'

import { revalidatePath } from 'next/cache'

import { clearCart } from '@/lib/cart'
import { LOCALES, SITE_URL, localeFromPrefix } from '@/lib/config'
import { GraphQLFailure, gql } from '@/lib/gql'
import { readCart } from '@/lib/session'

export interface Address { recipientName: string; phone?: string; street: string; exteriorNo?: string; interiorNo?: string; neighborhood?: string; city: string; state: string; countryCode: string; zip: string; references?: string }
export interface PaymentOption { provider: string; name: string; mode: 'SANDBOX' | 'LIVE' }
export interface ClientAction { type: 'redirect' | 'client_secret'; url?: string; client_secret?: string; publishable_key?: string }
export interface Result<T> { ok: boolean; data?: T; message?: string; code?: string | null; fields?: Record<string, string[]> }

function fail<T>(e: unknown): Result<T> {
  return e instanceof GraphQLFailure ? { ok: false, message: e.message, code: e.code, fields: e.fields } : { ok: false, message: 'unexpected' }
}

/** Freezes prices, taxes, shipping and addresses and holds the stock. The key makes a double click or a retry return the same checkout. */
export async function startCheckout(input: { email: string; shippingAddress: Address; billingAddress?: Address; idempotencyKey: string }): Promise<Result<{ id: string; options: PaymentOption[] }>> {
  const ref = await readCart()
  if (!ref) return { ok: false, message: 'empty', code: 'CART_EMPTY' }
  try {
    const start = await gql<{ startCheckout: { id: string } }>(`mutation($input: StartCheckoutInput!) { startCheckout(input: $input) { id } }`, { input: { cartId: ref.id, token: ref.token, ...input } })
    const id = start.startCheckout.id
    const opts = await gql<{ checkoutPaymentOptions: PaymentOption[] }>(`query($id: ID!, $token: String) { checkoutPaymentOptions(id: $id, token: $token) { provider name mode } }`, { id, token: ref.token })
    return { ok: true, data: { id, options: opts.checkoutPaymentOptions } }
  } catch (e) {
    return fail(e)
  }
}

export async function payCheckout(id: string, provider: string, localePrefix: string): Promise<Result<{ paymentId: string; clientAction: ClientAction | null; publicConfig: Record<string, string> | null; returnUrl: string }>> {
  const ref = await readCart()
  const locale = localeFromPrefix(localePrefix) ?? 'es-MX'
  const base = `${SITE_URL}/${LOCALES[locale].prefix}`
  const returnUrl = `${base}/checkout/return?checkout=${encodeURIComponent(id)}`
  try {
    const data = await gql<{ payCheckout: { paymentId: string; clientAction: ClientAction | null; publicConfig: Record<string, string> | null } }>(
      `mutation($id: ID!, $token: String, $provider: String!, $ok: String!, $no: String!) { payCheckout(id: $id, token: $token, provider: $provider, returnUrl: $ok, cancelUrl: $no) { paymentId clientAction publicConfig } }`,
      { id, token: ref?.token ?? null, provider, ok: returnUrl, no: `${base}/checkout` },
    )
    return { ok: true, data: { ...data.payCheckout, returnUrl } }
  } catch (e) {
    return fail(e)
  }
}

export interface Confirmation { status: string; paidAt: string | null }

/** The shopper came back from the provider. Safe to call repeatedly; the cart is only emptied once the payment is really in. */
export async function confirmCheckout(id: string): Promise<Result<Confirmation>> {
  const ref = await readCart()
  try {
    const data = await gql<{ confirmCheckoutPayment: Confirmation }>(`mutation($id: ID!, $token: String) { confirmCheckoutPayment(id: $id, token: $token) { status paidAt } }`, { id, token: ref?.token ?? null })
    if (data.confirmCheckoutPayment.paidAt) {
      await clearCart()
      revalidatePath('/', 'layout')
    }
    return { ok: true, data: data.confirmCheckoutPayment }
  } catch (e) {
    return fail(e)
  }
}
