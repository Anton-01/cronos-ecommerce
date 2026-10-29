import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { createContext, useCallback, useContext, useMemo, useSyncExternalStore, type ReactNode } from 'react'

import { AdminLogoutDocument, AdminMeDocument, type AdminMeQuery } from '@/graphql/generated'
import { clearSession, getToken, setToken, subscribe } from '@/lib/session'

type Me = NonNullable<AdminMeQuery['adminMe']>

interface AuthContextValue {
  status: 'loading' | 'authenticated' | 'anonymous'
  user: Me | null
  can: (permission: string) => boolean
  signIn: (token: string) => void
  signOut: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const client = useApolloClient()
  const token = useSyncExternalStore(subscribe, getToken, () => null)
  const { data, loading } = useQuery(AdminMeDocument, { skip: !token })
  const [logout] = useMutation(AdminLogoutDocument)

  const signIn = useCallback((value: string) => setToken(value), [])

  const signOut = useCallback(async () => {
    try {
      // Revoke server-side first; a failure must not keep the user signed in locally.
      await logout()
    } catch {
      /* token may already be invalid */
    }
    clearSession('signout')
    await client.clearStore()
  }, [client, logout])

  const value = useMemo<AuthContextValue>(() => {
    const user = token ? (data?.adminMe ?? null) : null
    const permissions = new Set(user?.permissions ?? [])
    return {
      status: !token ? 'anonymous' : loading && !data ? 'loading' : user ? 'authenticated' : 'anonymous',
      user,
      can: (permission) => permissions.has(permission),
      signIn,
      signOut,
    }
  }, [token, data, loading, signIn, signOut])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
