import 'server-only'

import { gql } from './gql'
import { readCart, writeCart, clearCart } from './session'
import type { Cart } from './types'

export const CART_FIELDS = `id market region couponCode couponError warning
  lines { productId sku name byRoll quantity unitPrice lineTotal discount tax total }
  totals { currency taxIncluded subtotal itemsDiscount shipping shippingDiscount tax total }`

/** The visitor's cart, or null when they have none yet (a cart is only created by the first item). A stale reference is forgotten. */
export async function getCart(): Promise<Cart | null> {
  const ref = await readCart()
  if (!ref) return null
  try {
    const data = await gql<{ cart: Cart }>(`query($id: ID!, $token: String) { cart(id: $id, token: $token) { ${CART_FIELDS} } }`, { id: ref.id, token: ref.token })
    return data.cart
  } catch {
    return null
  }
}

/** Finds or creates the cart in the given store and remembers it (guest token included) in an httpOnly cookie. */
export async function ensureCart(market: string): Promise<{ id: string; token: string | null }> {
  const ref = await readCart()
  const data = await gql<{ openCart: { cart: { id: string }; token: string | null } }>(
    `mutation($market: String!, $token: String) { openCart(market: $market, token: $token) { token cart { id } } }`,
    { market, token: ref?.token ?? null },
  )
  const token = data.openCart.token ?? ref?.token ?? null
  await writeCart({ id: data.openCart.cart.id, token, market })
  return { id: data.openCart.cart.id, token }
}

/**
 * After signing in, the guest cart (identified by its token) is merged into the account's by the API. The account cart's id replaces the guest one,
 * and the guest token is no longer needed.
 */
export async function openGuestCartIntoAccount(market: string | null): Promise<void> {
  const ref = await readCart()
  if (!ref?.token || !market) return
  try {
    const data = await gql<{ openCart: { cart: { id: string } } }>(`mutation($market: String!, $token: String) { openCart(market: $market, token: $token) { cart { id } } }`, { market: ref.market ?? market, token: ref.token })
    await writeCart({ id: data.openCart.cart.id, token: null, market: ref.market ?? market })
  } catch {
    // Keep the guest cart as it is: the shopper can still check out with it.
  }
}

export { clearCart }
