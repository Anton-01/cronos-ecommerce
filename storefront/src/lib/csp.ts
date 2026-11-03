/**
 * Content-Security-Policy of the storefront. Every page gets a fresh nonce: only the scripts and styles Next and this site emit carry it, so an
 * injected `<script>` (a stored-XSS bug, a compromised dependency) is refused by the browser instead of running.
 *
 * What is allowed outside the site itself, and why:
 *  - Cloudflare Turnstile (contact and newsletter forms): script + frame from challenges.cloudflare.com.
 *  - Stripe.js (card payments): script + frames from js.stripe.com / hooks.stripe.com, calls to api.stripe.com.
 *  - Google Fonts (the fonts the theme picks): stylesheet from fonts.googleapis.com, files from fonts.gstatic.com.
 *  - Pictures from the API's media origin (the renditions it produces) and any extra origin in CSP_IMG_SRC (a CDN).
 */
export interface CspOptions {
  nonce: string
  /** Origins pictures may come from, besides the site itself. */
  imgOrigins?: string[]
  dev?: boolean
  /** Send as Report-Only (log violations without blocking): the way to try a stricter policy on real traffic. */
  reportUri?: string
}

export function buildCsp({ nonce, imgOrigins = [], dev = false, reportUri }: CspOptions): string {
  const script = ["'self'", `'nonce-${nonce}'`, "'strict-dynamic'", 'https://challenges.cloudflare.com', 'https://js.stripe.com', ...(dev ? ["'unsafe-eval'"] : [])]
  const directives: Record<string, string[]> = {
    'default-src': ["'self'"],
    'script-src': script,
    // Inline style="" attributes are everywhere in React and cannot carry a nonce; <style> blocks and stylesheets can, so they are held to it.
    'style-src': ["'self'", `'nonce-${nonce}'`, 'https://fonts.googleapis.com'],
    'style-src-attr': ["'unsafe-inline'"],
    'font-src': ["'self'", 'https://fonts.gstatic.com', 'data:'],
    'img-src': ["'self'", 'data:', 'blob:', ...imgOrigins],
    'connect-src': ["'self'", 'https://challenges.cloudflare.com', 'https://api.stripe.com', ...(dev ? ['ws:', 'wss:'] : [])],
    'frame-src': ['https://challenges.cloudflare.com', 'https://js.stripe.com', 'https://hooks.stripe.com'],
    'worker-src': ["'self'", 'blob:'],
    'manifest-src': ["'self'"],
    'object-src': ["'none'"],
    'base-uri': ["'self'"],
    'form-action': ["'self'"],
    'frame-ancestors': ["'self'"],
  }
  const parts = Object.entries(directives).map(([k, v]) => `${k} ${v.join(' ')}`)
  if (!dev) parts.push('upgrade-insecure-requests')
  if (reportUri) parts.push(`report-uri ${reportUri}`)
  return parts.join('; ')
}

export function newNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  return btoa(String.fromCharCode(...bytes))
}

/** Origins to allow for pictures: the API's own, plus a comma-separated CSP_IMG_SRC. */
export function imgOrigins(apiUrl: string, extra = ''): string[] {
  const origins = new Set<string>()
  for (const raw of [apiUrl, ...extra.split(',')]) {
    try {
      const v = raw.trim()
      v && origins.add(new URL(v).origin)
    } catch {
      // A malformed entry must not break the policy: it is simply not allowed.
    }
  }
  return [...origins]
}
