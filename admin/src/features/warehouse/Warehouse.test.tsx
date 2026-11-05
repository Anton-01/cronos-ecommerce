import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'

import { App } from '@/App'
import { ClientContextDocument, WhAlertInboxDocument, WhScanDocument, WhWarehousesDocument } from '@/graphql/generated'
import { meMock, renderApp, type Mock } from '@/test/utils'

const OPERATOR = ['admin.access', 'warehouse.view', 'warehouse.operate']
const LEAD = [...OPERATOR, 'warehouse.approve', 'warehouse.locations', 'warehouse.audit']

// A valid server code (10 chars + check character).
const CODE = (() => {
  const a = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
  const body = '7K3M9Q2XA4'
  let sum = 0
  for (let i = 0; i < body.length; i++) sum += a.indexOf(body[i]!) * (i + 1)
  return body + a[sum % 32]
})()

const shared: Mock[] = [
  {
    request: { query: WhWarehousesDocument },
    result: { data: { whWarehouses: [{ __typename: 'AdminWarehouse', id: '1', code: 'MX-01', name: 'México', isDefault: true, isActive: true }] } },
    maxUsageCount: Number.POSITIVE_INFINITY,
  },
  {
    request: { query: WhAlertInboxDocument, variables: { unreadOnly: true, first: 1 } },
    result: { data: { whAlertInbox: { __typename: 'WhInbox', unread: 2, items: [] } } },
    maxUsageCount: Number.POSITIVE_INFINITY,
  },
  {
    request: { query: ClientContextDocument },
    result: { data: { clientContext: { __typename: 'ClientContext', ip: '1.1.1.1', country: 'MX', timezone: 'America/Mexico_City', utcOffset: '-06:00', source: 'ip' } } },
    maxUsageCount: Number.POSITIVE_INFINITY,
  },
]

const scan = (over: Record<string, unknown> = {}) => ({
  __typename: 'WhScan',
  productId: 'p1',
  sku: 'LIS-1',
  name: 'Listón rojo',
  type: 'simple',
  status: 'inactive',
  removed: false,
  stockCode: CODE,
  stockCodeFormatted: `${CODE.slice(0, 10)}-${CODE.slice(10)}`,
  unit: 'meter',
  pendingToPick: '0.000',
  places: [],
  books: [],
  thresholds: [],
  recent: [],
  ...over,
})

describe('warehouse screens', () => {
  it('sends the warehouse team straight to the warehouse, and shows only what their permissions allow', async () => {
    renderApp(<App />, { route: '/', signedIn: true, mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared] })

    expect(await screen.findByRole('heading', { name: 'Escanear etiqueta' })).toBeInTheDocument()
    const nav = screen.getByRole('navigation', { name: 'Almacén' })
    expect(within(nav).getByRole('link', { name: /Escanear/ })).toBeInTheDocument()
    expect(within(nav).getByRole('link', { name: /Surtir/ })).toBeInTheDocument()
    // The lead's sections are not offered to the operator.
    expect(within(nav).queryByRole('link', { name: /Trazabilidad/ })).toBeNull()
    expect(within(nav).queryByRole('link', { name: /Aprobaciones/ })).toBeNull()
    expect(within(nav).queryByRole('link', { name: /Reglas/ })).toBeNull()
    // and the rest of the back office is not in the sidebar
    expect(screen.queryByRole('link', { name: 'Pedidos' })).toBeNull()
    expect((await screen.findAllByLabelText('2 sin leer')).length).toBeGreaterThan(0)
  })

  it('refuses a direct visit to a lead-only section', async () => {
    renderApp(<App />, { route: '/warehouse/trail', signedIn: true, mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared] })
    expect(await screen.findByText('Sin acceso')).toBeInTheDocument()
  })

  it('opens a product from a typed code, tolerating lower case and the hyphen', async () => {
    renderApp(<App />, {
      route: '/warehouse',
      signedIn: true,
      mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared, { request: { query: WhScanDocument, variables: { code: CODE } }, result: { data: { whScan: scan() } } }],
    })
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Código de la etiqueta'), `${CODE.slice(0, 10)}-${CODE.slice(10)}`.toLowerCase())
    expect(screen.getByText(/Código válido/)).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Abrir' }))

    expect(await screen.findByRole('heading', { name: 'Listón rojo' })).toBeInTheDocument()
  })

  it('refuses a code with a mistyped character before asking the server', async () => {
    renderApp(<App />, { route: '/warehouse', signedIn: true, mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared] })
    const user = userEvent.setup()
    const bad = (CODE[0] === '2' ? '3' : '2') + CODE.slice(1)
    await user.type(await screen.findByLabelText('Código de la etiqueta'), bad)
    await user.click(screen.getByRole('button', { name: 'Abrir' }))
    expect(await screen.findByText(/no es válido/)).toBeInTheDocument()
  })

  it('shows an inactive product with no stock anyway, with its state in words', async () => {
    renderApp(<App />, {
      route: `/warehouse/item/${CODE}`,
      signedIn: true,
      mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared, { request: { query: WhScanDocument, variables: { code: CODE } }, result: { data: { whScan: scan() } } }],
    })
    expect(await screen.findByRole('heading', { name: 'Listón rojo' })).toBeInTheDocument()
    expect(screen.getByText('Inactivo')).toBeInTheDocument()
    expect(screen.getByText(/no está a la venta ahora/)).toBeInTheDocument()
    expect(screen.getByText(/Todavía no hay existencias de este producto/)).toBeInTheDocument()
    // the operator can receive stock into it
    expect(screen.getByRole('button', { name: /Recibir/ })).toBeInTheDocument()
  })

  it('shows a product that was removed from the catalogue, with its history kept', async () => {
    renderApp(<App />, {
      route: `/warehouse/item/${CODE}`,
      signedIn: true,
      mocks: [meMock(LEAD, { roles: ['warehouse-lead'] }), ...shared, { request: { query: WhScanDocument, variables: { code: CODE } }, result: { data: { whScan: scan({ removed: true }) } } }],
    })
    expect(await screen.findByText('Eliminado')).toBeInTheDocument()
    expect(screen.getByText(/fue eliminado del catálogo/)).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Ver todo' })).toHaveAttribute('href', '/warehouse/trail?product=p1')
  })

  it('a label QR opens the same screen through /w/<code>', async () => {
    renderApp(<App />, {
      route: `/w/${CODE}`,
      signedIn: true,
      mocks: [meMock(OPERATOR, { roles: ['warehouse'] }), ...shared, { request: { query: WhScanDocument, variables: { code: CODE } }, result: { data: { whScan: scan({ status: 'published' }) } } }],
    })
    expect(await screen.findByRole('heading', { name: 'Listón rojo' })).toBeInTheDocument()
  })
})
