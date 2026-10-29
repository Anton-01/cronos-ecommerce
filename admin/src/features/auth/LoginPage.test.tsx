import { GraphQLError } from 'graphql'
import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useLocation } from 'react-router-dom'
import { describe, expect, it } from 'vitest'

import { App } from '@/App'
import { AdminLoginDocument, AdminLogoutDocument, RolesDocument } from '@/graphql/generated'
import { getToken } from '@/lib/session'
import { meMock, renderApp } from '@/test/utils'

function LocationProbe() {
  const location = useLocation()
  return <output data-testid="location">{`${location.pathname}|${(location.state as { from?: string } | null)?.from ?? ''}`}</output>
}

const input = { email: 'boss@cronos.test', password: 'secret-password' }

async function fillAndSubmit(email = input.email, password = input.password) {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Correo'), email)
  await user.type(screen.getByLabelText('Contraseña'), password)
  await user.click(screen.getByRole('button', { name: 'Entrar' }))
}

describe('LoginPage', () => {
  it('validates the form before calling the API', async () => {
    renderApp(<App />, { mocks: [], route: '/login' })

    await userEvent.setup().click(await screen.findByRole('button', { name: 'Entrar' }))

    expect(await screen.findByText('Ingresa tu correo')).toBeInTheDocument()
    expect(screen.getByText('Ingresa tu contraseña')).toBeInTheDocument()
  })

  it('shows a friendly message for invalid credentials and stores no token', async () => {
    renderApp(<App />, {
      route: '/login',
      mocks: [
        {
          request: { query: AdminLoginDocument, variables: { input } },
          result: { errors: [new GraphQLError('The provided credentials are invalid.', { extensions: { code: 'INVALID_CREDENTIALS' } })] },
        },
      ],
    })

    await fillAndSubmit()

    expect(await screen.findByRole('alert')).toHaveTextContent('Correo o contraseña incorrectos.')
    expect(getToken()).toBeNull()
  })

  it('stores the token and lands on the dashboard after a successful login', async () => {
    renderApp(<App />, {
      route: '/login',
      mocks: [
        {
          request: { query: AdminLoginDocument, variables: { input } },
          result: { data: { adminLogin: { __typename: 'AuthPayload', token: '1|abc', expiresAt: null, user: { __typename: 'User', id: 'me' } } } },
        },
        meMock(['admin.access']),
      ],
    })

    await fillAndSubmit()

    expect(await screen.findByRole('heading', { name: 'Resumen' })).toBeInTheDocument()
    expect(getToken()).toBe('1|abc')
  })

  it('redirects anonymous visitors from protected routes to the login page', async () => {
    renderApp(<App />, { mocks: [], route: '/users' })

    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeInTheDocument()
  })

  it('blocks accounts without the admin.access permission', async () => {
    renderApp(<App />, { mocks: [meMock([])], route: '/', signedIn: true })

    await waitFor(() => expect(screen.getByText('Sin acceso')).toBeInTheDocument())
    expect(screen.queryByRole('heading', { name: 'Resumen' })).not.toBeInTheDocument()
  })

  it('does not send the next person to the previous user\'s last page after a sign-out', async () => {
    renderApp(
      <>
        <App />
        <LocationProbe />
      </>,
      {
      route: '/roles',
      signedIn: true,
      mocks: [
        meMock(['admin.access', 'roles.view'], { email: 'first@cronos.test' }),
        { request: { query: RolesDocument }, result: { data: { roles: [] } } },
        { request: { query: AdminLogoutDocument }, result: { data: { logout: true } } },
      ],
      },
    )
    const user = userEvent.setup()

    await screen.findByRole('heading', { name: 'Roles' })
    await user.click(within(screen.getByRole('banner')).getByRole('button'))
    await user.click(await screen.findByRole('menuitem', { name: /Cerrar sesión/ }))

    expect(await screen.findByRole('button', { name: 'Entrar' })).toBeInTheDocument()
    expect(getToken()).toBeNull()
    expect(screen.getByTestId('location')).toHaveTextContent(/^\/login\|$/)
  })
})
