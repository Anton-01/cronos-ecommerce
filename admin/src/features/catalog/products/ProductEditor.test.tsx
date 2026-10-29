import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'

import { App } from '@/App'
import { AdminAttributesDocument, AdminCategoriesDocument, AdminProductDocument, AdminTagsDocument, SupportedLocalesDocument, UpdateProductDocument } from '@/graphql/generated'
import { meMock, renderApp, type Mock } from '@/test/utils'

const locales: Mock = {
  request: { query: SupportedLocalesDocument },
  result: { data: { supportedLocales: [{ __typename: 'SupportedLocale', code: 'es-MX', isDefault: true }, { __typename: 'SupportedLocale', code: 'en-US', isDefault: false }] } },
  maxUsageCount: Number.POSITIVE_INFINITY,
}
const empty = (query: Mock['request']['query'], data: object): Mock => ({ request: { query }, result: { data }, maxUsageCount: Number.POSITIVE_INFINITY })

function product(overrides: Record<string, unknown> = {}) {
  return {
    __typename: 'AdminProduct',
    id: 'p1', parentId: null, type: 'SIMPLE', status: 'DRAFT', sku: 'LIST-1', barcode: null, price: '18.00', weight: null, dimensions: null,
    sales: { __typename: 'AdminSalesUnit', unit: 'METER', step: '0.500', min: '1.000', max: '50.000', sellByCut: true, sellByRoll: true, rollLength: '25.000', rollPrice: '400.00' },
    manageStock: true, allowBackorder: false, lowStockThreshold: null, stockQuantity: '0.000', isCustomizable: false, publishedAt: null,
    translations: [{ __typename: 'ProductTranslation', locale: 'es-MX', name: 'Listón de satén', slug: 'liston-de-saten', shortDescription: null, description: null, metaTitle: null, metaDescription: null }],
    categoryIds: [], primaryCategoryId: null, tagIds: [], attributes: [], offeredValueIds: [], groupItems: [], variations: [], gallery: [],
    publishProblems: [],
    ...overrides,
  }
}

const PERMS = ['admin.access', 'catalog.view', 'catalog.manage', 'catalog.publish']

function setup(p: Record<string, unknown>, perms = PERMS, extra: Mock[] = []) {
  return renderApp(<App />, {
    route: '/catalog/products/p1',
    signedIn: true,
    mocks: [
      meMock(perms),
      locales,
      { request: { query: AdminProductDocument, variables: { id: 'p1' } }, result: { data: { adminProduct: p } }, maxUsageCount: Number.POSITIVE_INFINITY },
      empty(AdminCategoriesDocument, { adminCategories: [] }),
      empty(AdminTagsDocument, { adminTags: [] }),
      empty(AdminAttributesDocument, { adminAttributes: [] }),
      ...extra,
    ],
  })
}

describe('ProductEditor', () => {
  it('shows the tabs that fit the product type', async () => {
    setup(product({ type: 'VARIABLE' }))

    expect(await screen.findByRole('heading', { name: 'Listón de satén' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Venta' })).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Variaciones' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Kit' })).not.toBeInTheDocument()
  })

  it('a grouped product has a kit tab and no sales tab', async () => {
    setup(product({ type: 'GROUPED', sales: { __typename: 'AdminSalesUnit', unit: 'PIECE', step: '1.000', min: '1.000', max: null, sellByCut: true, sellByRoll: false, rollLength: null, rollPrice: null } }))

    expect(await screen.findByRole('tab', { name: 'Kit' })).toBeInTheDocument()
    expect(screen.queryByRole('tab', { name: 'Venta' })).not.toBeInTheDocument()
  })

  it('loads the ribbon configuration and saves a change to the roll price', async () => {
    let sent: unknown = null
    const save: Mock = {
      request: {
        query: UpdateProductDocument,
        variables: (variables: Record<string, unknown>) => {
          sent = variables
          return true
        },
      },
      result: { data: { updateProduct: product() } },
    }
    setup(product(), PERMS, [save])
    const user = userEvent.setup()

    await user.click(await screen.findByRole('tab', { name: 'Venta' }))
    expect(screen.getByLabelText('Se vende por')).toHaveValue('METER')
    expect(screen.getByLabelText('Metros por rollo')).toHaveValue('25')
    expect(screen.getByLabelText('Resumen de venta')).toHaveTextContent('Rollo completo de 25 m a $400.00 por rollo.')

    const rollPrice = screen.getByLabelText('Precio por rollo')
    await user.clear(rollPrice)
    await user.type(rollPrice, '420')
    await user.click(screen.getByRole('button', { name: 'Guardar venta' }))

    await waitFor(() => expect(sent).not.toBeNull())
    expect(sent).toMatchObject({
      id: 'p1',
      input: { price: '18.00', sales: { unit: 'METER', step: '0.5', min: '1', max: '50', sellByCut: true, sellByRoll: true, rollLength: '25', rollPrice: '420' } },
    })
  })

  it('blocks saving an invalid sales configuration', async () => {
    setup(product())
    const user = userEvent.setup()

    await user.click(await screen.findByRole('tab', { name: 'Venta' }))
    const min = screen.getByLabelText('Mínimo (m)')
    await user.clear(min)
    await user.type(min, '1.2')

    expect(screen.getByRole('alert')).toHaveTextContent('múltiplo del paso')
    expect(screen.getByRole('button', { name: 'Guardar venta' })).toBeDisabled()
  })

  it('explains what is missing before a draft can be published', async () => {
    setup(product({ sku: null, price: null, publishProblems: ['the SKU is required', 'a price is required'] }))
    const user = userEvent.setup()

    expect(await screen.findByRole('tab', { name: 'Publicación (2)' })).toBeInTheDocument()
    await user.click(screen.getByRole('tab', { name: 'Publicación (2)' }))

    expect(screen.getByText('the SKU is required')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Publicar' })).toBeDisabled()
  })

  it('lets a publisher publish a product that is ready', async () => {
    setup(product())
    const user = userEvent.setup()
    await user.click(await screen.findByRole('tab', { name: 'Publicación' }))
    expect(screen.getByRole('button', { name: 'Publicar' })).toBeEnabled()
  })

  it('hides publishing from staff without catalog.publish', async () => {
    setup(product(), ['admin.access', 'catalog.view', 'catalog.manage'])
    const user = userEvent.setup()

    await user.click(await screen.findByRole('tab', { name: 'Publicación' }))

    expect(screen.queryByRole('button', { name: 'Publicar' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Generar enlace' })).toBeInTheDocument()
  })

  it('is read-only for staff who can only view the catalogue', async () => {
    setup(product(), ['admin.access', 'catalog.view'])

    await screen.findByRole('heading', { name: 'Listón de satén' })
    expect(document.getElementById('name-es-MX')).toBeDisabled()
    expect(screen.queryByRole('button', { name: 'Guardar cambios' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Eliminar' })).not.toBeInTheDocument()
  })
})
