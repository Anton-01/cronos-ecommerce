import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'

import { App } from '@/App'
import { AdminSettingsDocument, UpdateSettingsDocument } from '@/graphql/generated'
import { meMock, renderApp, type Mock } from '@/test/utils'

const base = { __typename: 'AdminSetting' as const, public: true, translations: [] as { __typename: 'SettingTranslation'; locale: string; value: string | null }[], value: null as unknown }

const settings = [
  { ...base, group: 'identity', key: 'store_name', type: 'string', label: 'Nombre de la tienda', translatable: true, translations: [{ __typename: 'SettingTranslation' as const, locale: 'es-MX', value: 'Cronos' }] },
  { ...base, group: 'identity', key: 'logo', type: 'media', label: 'Logotipo', translatable: false },
  { ...base, group: 'contact', key: 'email', type: 'email', label: 'Correo de contacto', translatable: false, value: 'hola@cronos.test' },
]

const query: Mock = {
  request: { query: AdminSettingsDocument },
  result: {
    data: {
      adminSettings: settings,
      supportedLocales: [
        { __typename: 'SupportedLocale', code: 'es-MX', isDefault: true },
        { __typename: 'SupportedLocale', code: 'en-US', isDefault: false },
      ],
    },
  },
  maxUsageCount: Number.POSITIVE_INFINITY,
}

describe('SettingsPage', () => {
  it('builds the form from the server catalogue and sends only what changed', async () => {
    let sent: unknown = null
    const mutation: Mock = {
      request: {
        query: UpdateSettingsDocument,
        variables: (variables: Record<string, unknown>) => {
          sent = variables
          return true
        },
      },
      result: { data: { updateSettings: settings } },
    }
    renderApp(<App />, { route: '/settings', signedIn: true, mocks: [meMock(['admin.access', 'settings.view', 'settings.update']), query, mutation] })
    const user = userEvent.setup()

    expect(await screen.findByRole('tab', { name: 'Identidad' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Contacto' })).toBeInTheDocument()
    expect(screen.getByText('Sin cambios')).toBeInTheDocument()

    // Translatable key: one input per locale, pre-filled from the server.
    const es = screen.getByLabelText('Nombre de la tienda (es-MX)')
    expect(es).toHaveValue('Cronos')
    expect(screen.getByLabelText('Nombre de la tienda (en-US)')).toHaveValue('')

    await user.type(screen.getByLabelText('Nombre de la tienda (en-US)'), 'Cronos Ribbons')
    expect(screen.getByText('1 cambio sin guardar')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Guardar cambios' }))

    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toEqual({
      input: [
        {
          group: 'identity',
          key: 'store_name',
          translations: [
            { locale: 'es-MX', value: 'Cronos' },
            { locale: 'en-US', value: 'Cronos Ribbons' },
          ],
        },
      ],
    })
  })

  it('is read-only for staff without settings.update', async () => {
    renderApp(<App />, { route: '/settings', signedIn: true, mocks: [meMock(['admin.access', 'settings.view']), query] })

    expect(await screen.findByLabelText('Nombre de la tienda (es-MX)')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Guardar cambios' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Elegir imagen' })).not.toBeInTheDocument()
  })

  it('is not reachable without settings.view', async () => {
    renderApp(<App />, { route: '/settings', signedIn: true, mocks: [meMock(['admin.access']), query] })

    expect(await screen.findByText('Sin acceso')).toBeInTheDocument()
  })
})
