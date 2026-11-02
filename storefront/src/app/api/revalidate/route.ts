import { timingSafeEqual } from 'node:crypto'

import { revalidateTag } from 'next/cache'
import type { NextRequest } from 'next/server'

/** Tags the API may refresh: the things the shop caches for hours. Anything else is ignored. */
const ALLOWED = new Set(['theme', 'content', 'categories', 'markets', 'products'])

function sameSecret(a: string, b: string): boolean {
  const x = Buffer.from(a)
  const y = Buffer.from(b)
  return x.length === y.length && timingSafeEqual(x, y)
}

/**
 * The API calls this when an editor changes something the shop keeps in its cache (a page, the theme, a category…). Without it the shop would
 * show the old version until the cache's own time limit; with it, a change shows within seconds and the API is asked again only once.
 */
export async function POST(request: NextRequest) {
  const secret = process.env.REVALIDATE_SECRET ?? ''
  const given = request.headers.get('x-revalidate-secret') ?? ''
  if (secret === '' || !sameSecret(secret, given)) return Response.json({ ok: false }, { status: 401 })

  const body = (await request.json().catch(() => null)) as { tags?: unknown } | null
  const tags = Array.isArray(body?.tags) ? body.tags.filter((t): t is string => typeof t === 'string' && ALLOWED.has(t)) : []
  for (const tag of tags) revalidateTag(tag, { expire: 0 })
  return Response.json({ ok: true, tags })
}
