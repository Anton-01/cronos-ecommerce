import { ApolloClient, ApolloLink, HttpLink, InMemoryCache } from '@apollo/client'
import { CombinedGraphQLErrors } from '@apollo/client/errors'
import { ErrorLink } from '@apollo/client/link/error'
import { SetContextLink } from '@apollo/client/link/context'

import { clearSession, getToken } from './session'

export const GRAPHQL_URL: string = import.meta.env.VITE_GRAPHQL_URL ?? 'http://localhost:8080/admin/graphql'

const authLink = new SetContextLink((prev) => {
  const token = getToken()
  return { headers: { ...prev.headers, ...(token ? { Authorization: `Bearer ${token}` } : {}) } }
})

// A rejected token means the session is over: drop it so the router sends the user to /login.
const errorLink = new ErrorLink(({ error, operation }) => {
  if (
    operation.operationName !== 'AdminLogin' &&
    CombinedGraphQLErrors.is(error) &&
    error.errors.some((e) => e.message === 'Unauthenticated.')
  ) {
    clearSession()
  }
})

export function createApolloClient(uri: string = GRAPHQL_URL) {
  return new ApolloClient({
    link: ApolloLink.from([errorLink, authLink, new HttpLink({ uri })]),
    cache: new InMemoryCache({
      typePolicies: {
        // Settings have no id: their natural key lets mutation results update the cached list in place.
        AdminSetting: { keyFields: ['group', 'key'] },
        // Providers are identified by their code: saving credentials or switching mode refreshes the card in place.
        AdminPaymentProvider: { keyFields: ['code'] },
        // Pages are cached per (search, order, size, cursor) so going "back" is instant.
        Query: { fields: { users: { keyArgs: ['search', 'orderBy', 'first', 'after'] } } },
      },
    }),
  })
}
