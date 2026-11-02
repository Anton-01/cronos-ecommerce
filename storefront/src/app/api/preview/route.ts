import { cookies } from 'next/headers'
import { NextResponse, type NextRequest } from 'next/server'

/** The back office's "Vista previa" link lands here: it remembers the token for an hour and shows the unpublished draft. `?token=` empty ends the preview. */
export async function GET(request: NextRequest) {
  const token = request.nextUrl.searchParams.get('token')
  const jar = await cookies()
  if (token) jar.set('theme_preview', token, { httpOnly: true, sameSite: 'lax', path: '/', maxAge: 3600 })
  else jar.delete('theme_preview')
  return NextResponse.redirect(new URL('/', request.url))
}
