import { describe, expect, it, vi } from 'vitest'

import { clearSession, getToken, setToken, subscribe } from './session'

describe('session store', () => {
  it('stores the token in sessionStorage and notifies subscribers', () => {
    const listener = vi.fn()
    const unsubscribe = subscribe(listener)

    setToken('abc')
    expect(getToken()).toBe('abc')
    expect(sessionStorage.getItem('cronos.admin.token')).toBe('abc')

    clearSession()
    expect(getToken()).toBeNull()
    expect(sessionStorage.getItem('cronos.admin.token')).toBeNull()
    expect(listener).toHaveBeenCalledTimes(2)

    unsubscribe()
    setToken('def')
    expect(listener).toHaveBeenCalledTimes(2)
    clearSession()
  })
})
