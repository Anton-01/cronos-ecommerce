import { ApolloClient, ApolloLink, InMemoryCache } from '@apollo/client'
import { ApolloProvider } from '@apollo/client/react'
import { MockLink } from '@apollo/client/testing'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { Toaster } from 'sonner'

import { AuthProvider } from '@/features/auth/AuthProvider'
import { AdminMeDocument } from '@/graphql/generated'
import { setToken } from '@/lib/session'

export type Mock = MockLink.MockedResponse

export function meMock(permissions: string[], overrides: Partial<{ id: string; email: string; roles: string[] }> = {}): Mock {
  return {
    request: { query: AdminMeDocument },
    result: {
      data: {
        adminMe: {
          __typename: 'User',
          id: overrides.id ?? 'me',
          email: overrides.email ?? 'boss@cronos.test',
          roles: overrides.roles ?? ['admin'],
          avatarUrl: null,
          permissions,
          profile: { __typename: 'UserProfile', firstName: 'Boss', lastName: null },
        },
      },
    },
    maxUsageCount: Number.POSITIVE_INFINITY,
  }
}

export function renderApp(ui: ReactElement, opts: { mocks: Mock[]; route?: string; signedIn?: boolean }) {
  if (opts.signedIn) setToken('test-token')

  const client = new ApolloClient({
    cache: new InMemoryCache({ typePolicies: { Query: { fields: { users: { keyArgs: ['search', 'orderBy', 'first', 'after'] } } } } }),
    link: ApolloLink.from([new MockLink(opts.mocks)]),
  })

  return {
    client,
    ...render(
      <ApolloProvider client={client}>
        <MemoryRouter initialEntries={[opts.route ?? '/']}>
          <AuthProvider>{ui}</AuthProvider>
          <Toaster />
        </MemoryRouter>
      </ApolloProvider>,
    ),
  }
}

export function userNode(n: number, extra: Partial<{ roles: string[]; isActive: boolean }> = {}) {
  return {
    __typename: 'User' as const,
    id: `u${n}`,
    email: `user${n}@example.com`,
    isActive: extra.isActive ?? true,
    emailVerifiedAt: '2026-01-01T00:00:00Z',
    roles: extra.roles ?? ['customer'],
    createdAt: `2026-02-0${n}T00:00:00Z`,
    profile: { __typename: 'UserProfile' as const, firstName: `Name${n}`, lastName: null, phone: null, locale: 'es-MX', countryCode: null },
  }
}
