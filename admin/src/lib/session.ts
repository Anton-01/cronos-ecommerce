// The bearer token lives in sessionStorage: it dies with the tab and is never sent
// to other origins. A tiny external store lets React and the Apollo link share it.
const KEY = 'cronos.admin.token'
const listeners = new Set<() => void>()

function read(): string | null {
  try {
    return sessionStorage.getItem(KEY)
  } catch {
    return null
  }
}

let current = read()
let endReason: 'signout' | 'expired' | null = null

export function getToken(): string | null {
  return current
}

export function setToken(token: string): void {
  current = token
  endReason = null
  try {
    sessionStorage.setItem(KEY, token)
  } catch {
    /* storage unavailable (private mode): keep the in-memory token only */
  }
  listeners.forEach((l) => l())
}

/** Why the last session ended: the user left on purpose, or the API rejected the token. */
export function getEndReason(): 'signout' | 'expired' | null {
  return endReason
}

export function clearSession(reason: 'signout' | 'expired' = 'expired'): void {
  current = null
  endReason = reason
  try {
    sessionStorage.removeItem(KEY)
  } catch {
    /* ignore */
  }
  listeners.forEach((l) => l())
}

export function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}
