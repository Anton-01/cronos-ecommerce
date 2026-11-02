import 'server-only'

import { cookies, headers } from 'next/headers'

import { API_URL } from './config'

export class GraphQLFailure extends Error {
  constructor(
    message: string,
    readonly code: string | null,
    readonly fields: Record<string, string[]> = {},
  ) {
    super(message)
  }
}

interface Options {
  /** Seconds the answer may be reused (ISR style). Omit for per-visitor data: it is then never cached. */
  revalidate?: number
  tags?: string[]
  /** Send the visitor's IP and sign-in so the API answers for them. Implied when nothing is cached. */
  asVisitor?: boolean
}

/**
 * Calls the storefront schema from the server. Public, cacheable data (theme, categories, listings) is fetched without the visitor's
 * identity so it can be shared; anything personal forwards their IP (the API decides country and clock from it) and their session.
 */
export async function gql<T>(query: string, variables: Record<string, unknown> = {}, options: Options = {}): Promise<T> {
  const personal = options.revalidate === undefined || options.asVisitor === true
  const send: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' }

  if (personal) {
    const h = await headers()
    const forwarded = h.get('x-forwarded-for')
    const ip = forwarded ?? h.get('x-real-ip')
    if (ip) send['X-Forwarded-For'] = ip
    const token = (await cookies()).get('session')?.value
    if (token) send.Authorization = `Bearer ${token}`
  }

  const response = await fetch(`${API_URL}/graphql`, {
    method: 'POST',
    headers: send,
    body: JSON.stringify({ query, variables }),
    ...(personal ? { cache: 'no-store' as const } : { next: { revalidate: options.revalidate, tags: options.tags } }),
  })
  if (!response.ok) throw new GraphQLFailure(`The API answered ${response.status}.`, 'HTTP')
  const body = (await response.json()) as { data?: T; errors?: { message: string; extensions?: { code?: string; validation?: Record<string, string[]> } }[] }
  if (body.errors?.length) {
    const first = body.errors[0]!
    throw new GraphQLFailure(first.message, first.extensions?.code ?? null, first.extensions?.validation ?? {})
  }
  return body.data as T
}
