import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { App } from '@/App'
import { MediaLibraryDocument } from '@/graphql/generated'
import { meMock, renderApp, type Mock } from '@/test/utils'

function item(n: number, status: 'READY' | 'PENDING' | 'FAILED' = 'READY') {
  return {
    __typename: 'Media' as const,
    id: `m${n}`,
    name: `foto-${n}`,
    fileName: `m${n}.jpg`,
    mimeType: 'image/jpeg',
    size: 2048 * n,
    width: 800,
    height: 600,
    status,
    failureReason: status === 'FAILED' ? 'disk exploded' : null,
    collectionName: 'library',
    isImage: true,
    url: `http://cdn/m${n}/m${n}.jpg`,
    thumbUrl: `http://cdn/m${n}/conversions/thumb.webp`,
    createdAt: '2026-10-29T00:00:00Z',
    conversions: [],
    translations: [],
  }
}

const vars = { first: 24, after: null, search: null, kind: null }
const list = (nodes: ReturnType<typeof item>[]): Mock => ({
  request: { query: MediaLibraryDocument, variables: vars },
  result: {
    data: {
      media: {
        __typename: 'MediaConnection',
        pageInfo: { __typename: 'PageInfo', hasNextPage: false, endCursor: null, total: nodes.length },
        edges: nodes.map((node) => ({ __typename: 'MediaEdge', cursor: node.id, node })),
      },
    },
  },
  maxUsageCount: Number.POSITIVE_INFINITY,
})

afterEach(() => vi.unstubAllGlobals())

describe('MediaPage', () => {
  it('shows thumbnails with the processing state of each file', async () => {
    renderApp(<App />, { route: '/media', signedIn: true, mocks: [meMock(['admin.access', 'media.view']), list([item(1), item(2, 'PENDING'), item(3, 'FAILED')])] })

    expect(await screen.findByRole('button', { name: 'foto-1' })).toBeInTheDocument()
    expect(screen.getByText('En cola')).toBeInTheDocument()
    expect(screen.getByText('Error')).toBeInTheDocument()
    expect(screen.getByAltText('foto-1')).toHaveAttribute('src', 'http://cdn/m1/conversions/thumb.webp')
    expect(screen.getByText(/1–3 de 3/)).toBeInTheDocument()
    expect(screen.getByText(/procesando miniaturas/)).toBeInTheDocument()
  })

  it('uploads the chosen files and surfaces API rejections per file', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({ json: async () => ({ data: { uploadMedia: { id: 'x' } } }) })
      .mockResolvedValueOnce({ json: async () => ({ errors: [{ message: 'x', extensions: { code: 'UNSUPPORTED_MEDIA_TYPE' } }] }) })
    vi.stubGlobal('fetch', fetchMock)
    renderApp(<App />, { route: '/media', signedIn: true, mocks: [meMock(['admin.access', 'media.view', 'media.upload']), list([item(1)])] })

    const input = await screen.findByLabelText('Elegir archivos')
    await userEvent.setup({ applyAccept: false }).upload(input, [new File(['a'], 'ok.png', { type: 'image/png' }), new File(['b'], 'bad.exe', { type: 'application/x-msdownload' })])

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2))
    expect(await screen.findByText('ok.png subido')).toBeInTheDocument()
    expect(await screen.findByText(/bad\.exe: Tipo de archivo no permitido/)).toBeInTheDocument()
  })

  it('hides the upload controls from read-only staff', async () => {
    renderApp(<App />, { route: '/media', signedIn: true, mocks: [meMock(['admin.access', 'media.view']), list([item(1)])] })

    await screen.findByRole('button', { name: 'foto-1' })
    expect(screen.queryByRole('button', { name: /Subir archivos/ })).not.toBeInTheDocument()
    expect(screen.queryByLabelText('Elegir archivos')).not.toBeInTheDocument()
  })
})
