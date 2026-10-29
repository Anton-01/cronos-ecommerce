import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'

import { App } from '@/App'
import { RolesDocument, UsersDocument } from '@/graphql/generated'
import { meMock, renderApp, userNode, type Mock } from '@/test/utils'

const base = { first: 25, after: null, search: null, orderBy: [{ column: 'CREATED_AT', order: 'DESC' }] }

function page(nodes: ReturnType<typeof userNode>[], opts: { hasNextPage: boolean; endCursor: string | null; total: number }): Mock['result'] {
  return {
    data: {
      users: {
        __typename: 'UserConnection',
        pageInfo: { __typename: 'PageInfo', hasNextPage: opts.hasNextPage, endCursor: opts.endCursor, total: opts.total },
        edges: nodes.map((node) => ({ __typename: 'UserEdge', cursor: `cur-${node.id}`, node })),
      },
    },
  }
}

const STAFF = ['admin.access', 'users.view']

describe('UsersPage', () => {
  it('renders rows from the API and follows the cursor to the next page', async () => {
    renderApp(<App />, {
      route: '/users',
      signedIn: true,
      mocks: [
        meMock(STAFF),
        { request: { query: UsersDocument, variables: { ...base, first: 25 } }, result: page([userNode(1), userNode(2)], { hasNextPage: true, endCursor: 'c1', total: 3 }) },
        { request: { query: UsersDocument, variables: { ...base, after: 'c1' } }, result: page([userNode(3)], { hasNextPage: false, endCursor: null, total: 3 }) },
      ],
    })

    expect(await screen.findByText('user1@example.com')).toBeInTheDocument()
    expect(screen.getByText('1–2 de 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeDisabled()

    await userEvent.setup().click(screen.getByRole('button', { name: 'Siguiente' }))

    expect(await screen.findByText('user3@example.com')).toBeInTheDocument()
    expect(screen.getByText('3–3 de 3')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Siguiente' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Anterior' })).toBeEnabled()
  })

  it('sorts on the server when a header is clicked', async () => {
    renderApp(<App />, {
      route: '/users',
      signedIn: true,
      mocks: [
        meMock(STAFF),
        { request: { query: UsersDocument, variables: base }, result: page([userNode(1), userNode(2)], { hasNextPage: false, endCursor: null, total: 2 }) },
        {
          request: { query: UsersDocument, variables: { ...base, orderBy: [{ column: 'EMAIL', order: 'ASC' }] } },
          result: page([userNode(2), userNode(1)], { hasNextPage: false, endCursor: null, total: 2 }),
        },
      ],
    })

    await screen.findByText('user1@example.com')
    await userEvent.setup().click(screen.getByRole('button', { name: /correo/i }))

    await waitFor(() => {
      const rows = screen.getAllByRole('row').slice(1)
      expect(within(rows[0]!).getByText('user2@example.com')).toBeInTheDocument()
    })
  })

  it('searches by email after the debounce', async () => {
    renderApp(<App />, {
      route: '/users',
      signedIn: true,
      mocks: [
        meMock(STAFF),
        { request: { query: UsersDocument, variables: base }, result: page([userNode(1), userNode(2)], { hasNextPage: false, endCursor: null, total: 2 }) },
        { request: { query: UsersDocument, variables: { ...base, search: 'user2' } }, result: page([userNode(2)], { hasNextPage: false, endCursor: null, total: 1 }) },
      ],
    })

    await screen.findByText('user1@example.com')
    await userEvent.setup().type(screen.getByLabelText('Buscar por correo'), 'user2')

    await waitFor(() => expect(screen.queryByText('user1@example.com')).not.toBeInTheDocument())
    expect(screen.getByText('user2@example.com')).toBeInTheDocument()
  })

  it('hides destructive actions from staff without users.update', async () => {
    renderApp(<App />, {
      route: '/users',
      signedIn: true,
      mocks: [meMock(STAFF), { request: { query: UsersDocument, variables: base }, result: page([userNode(1)], { hasNextPage: false, endCursor: null, total: 1 }) }],
    })

    await userEvent.setup().click(await screen.findByText('user1@example.com'))

    expect(await screen.findByRole('dialog')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Deshabilitar' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar roles' })).not.toBeInTheDocument()
  })

  it('lets an admin change roles', async () => {
    const roles: Mock['result'] = { data: { roles: [{ __typename: 'Role', id: '1', name: 'customer', permissions: [] }, { __typename: 'Role', id: '2', name: 'support', permissions: [] }] } }
    renderApp(<App />, {
      route: '/users',
      signedIn: true,
      mocks: [
        meMock([...STAFF, 'users.update', 'users.assign-roles', 'roles.view']),
        { request: { query: UsersDocument, variables: base }, result: page([userNode(1)], { hasNextPage: false, endCursor: null, total: 1 }) },
        { request: { query: RolesDocument }, result: roles },
      ],
    })
    const user = userEvent.setup()

    await user.click(await screen.findByText('user1@example.com'))

    const support = await screen.findByLabelText('support')
    expect(screen.getByRole('button', { name: 'Guardar roles' })).toBeDisabled()
    await user.click(support)
    expect(screen.getByRole('button', { name: 'Guardar roles' })).toBeEnabled()
  })
})
