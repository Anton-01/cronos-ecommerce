'use server'

import { revalidatePath } from 'next/cache'

import { CART_FIELDS, ensureCart } from '@/lib/cart'
import { GraphQLFailure, gql } from '@/lib/gql'
import { readCart, readMarket, writeCart, writeMarket } from '@/lib/session'
import type { Cart } from '@/lib/types'

export interface ActionResult { ok: boolean; message?: string; code?: string | null; cart?: Cart; replaced?: boolean }

const fail = (e: unknown): ActionResult => (e instanceof GraphQLFailure ? { ok: false, message: e.message, code: e.code } : { ok: false, message: 'Something went wrong. Please try again.' })

export async function addToCart(productId: string, quantity: string, byRoll: boolean, market: string): Promise<ActionResult> {
  try {
    const { id, token } = await ensureCart(market)
    const current = await gql<{ cart: Cart }>(`query($id: ID!, $token: String) { cart(id: $id, token: $token) { lines { productId byRoll quantity } } }`, { id, token })
    // The cart holds ONE line per product. Adding again in the same way of buying adds to it; switching between cut and roll replaces it
    // (the shopper is told). The server enforces step, minimum and stock either way.
    const line = current.cart.lines.find((l) => l.productId === productId)
    const replaced = line !== undefined && line.byRoll !== byRoll
    const total = (replaced ? Number(quantity) : Number(line?.quantity ?? 0) + Number(quantity)).toString()
    const data = await gql<{ setCartItem: Cart }>(
      `mutation($id: ID!, $token: String, $product: ID!, $qty: String!, $roll: Boolean) { setCartItem(cartId: $id, token: $token, productId: $product, quantity: $qty, byRoll: $roll) { ${CART_FIELDS} } }`,
      { id, token, product: productId, qty: total, roll: byRoll },
    )
    revalidatePath('/', 'layout')
    return { ok: true, cart: data.setCartItem, replaced }
  } catch (e) {
    return fail(e)
  }
}

export async function setLine(productId: string, quantity: string, byRoll: boolean): Promise<ActionResult> {
  const ref = await readCart()
  if (!ref) return { ok: false, message: 'Your cart is empty.' }
  try {
    const data = await gql<{ setCartItem: Cart }>(
      `mutation($id: ID!, $token: String, $product: ID!, $qty: String!, $roll: Boolean) { setCartItem(cartId: $id, token: $token, productId: $product, quantity: $qty, byRoll: $roll) { ${CART_FIELDS} } }`,
      { id: ref.id, token: ref.token, product: productId, qty: quantity, roll: byRoll },
    )
    revalidatePath('/', 'layout')
    return { ok: true, cart: data.setCartItem }
  } catch (e) {
    return fail(e)
  }
}

export async function removeLine(productId: string): Promise<ActionResult> {
  const ref = await readCart()
  if (!ref) return { ok: true }
  try {
    const data = await gql<{ removeCartItem: Cart }>(
      `mutation($id: ID!, $token: String, $product: ID!) { removeCartItem(cartId: $id, token: $token, productId: $product) { ${CART_FIELDS} } }`,
      { id: ref.id, token: ref.token, product: productId },
    )
    revalidatePath('/', 'layout')
    return { ok: true, cart: data.removeCartItem }
  } catch (e) {
    return fail(e)
  }
}

export async function applyCoupon(code: string | null): Promise<ActionResult> {
  const ref = await readCart()
  if (!ref) return { ok: false, message: 'Add something to your cart first.' }
  try {
    const data = await gql<{ applyCartCoupon: Cart }>(
      `mutation($id: ID!, $token: String, $code: String) { applyCartCoupon(cartId: $id, token: $token, code: $code) { ${CART_FIELDS} } }`,
      { id: ref.id, token: ref.token, code: code && code.trim() !== '' ? code.trim() : null },
    )
    revalidatePath('/', 'layout')
    return { ok: true, cart: data.applyCartCoupon }
  } catch (e) {
    return fail(e)
  }
}

/** The visitor chose a store (the "you are outside your country" notice or the selector). Their cart follows when it still can. */
export async function chooseMarket(code: string): Promise<ActionResult> {
  await writeMarket(code)
  const ref = await readCart()
  if (ref && ref.market !== code) {
    try {
      await gql(`mutation($id: ID!, $token: String, $market: String!) { setCartMarket(cartId: $id, token: $token, market: $market) { id } }`, { id: ref.id, token: ref.token, market: code })
      await writeCart({ ...ref, market: code })
    } catch {
      // A cart that cannot move (e.g. a product not sold there) stays where it was; the cart page explains what changed.
    }
  }
  revalidatePath('/', 'layout')
  return { ok: true }
}

export async function currentMarket(): Promise<string | null> {
  return readMarket()
}
