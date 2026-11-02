import { cookies } from 'next/headers'
import type { NextRequest } from 'next/server'

import { API_URL } from '@/lib/config'

/**
 * Browser → API bridge. The browser only ever talks to this site (no CORS, and the sign-in token lives in an httpOnly cookie that
 * scripts cannot read); here it becomes an Authorization header, and the real client IP travels along for country and clock.
 */
export async function POST(request: NextRequest) {
  const token = (await cookies()).get('session')?.value
  const forwarded = request.headers.get('x-forwarded-for')
  const headers: Record<string, string> = {
    'Content-Type': request.headers.get('content-type') ?? 'application/json',
    Accept: 'application/json',
  }
  if (forwarded) headers['X-Forwarded-For'] = forwarded
  if (token) headers.Authorization = `Bearer ${token}`

  const upstream = await fetch(`${API_URL}/graphql`, { method: 'POST', headers, body: await request.text(), cache: 'no-store' })
  return new Response(upstream.body, { status: upstream.status, headers: { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' } })
}
