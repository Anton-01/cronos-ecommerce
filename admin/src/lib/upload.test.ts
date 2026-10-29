import { afterEach, describe, expect, it, vi } from 'vitest'

import { UploadMediaDocument } from '@/graphql/generated'

import { getToken, setToken } from './session'
import { UploadError, uploadFile } from './upload'

const file = new File(['png-bytes'], 'foto.png', { type: 'image/png' })

function stubFetch(body: unknown) {
  const fn = vi.fn().mockResolvedValue({ json: async () => body })
  vi.stubGlobal('fetch', fn)
  return fn
}

afterEach(() => vi.unstubAllGlobals())

describe('uploadFile', () => {
  it('sends the GraphQL multipart request with the bearer token and the file mapped to the variable', async () => {
    setToken('tok')
    const fetchMock = stubFetch({ data: { uploadMedia: { id: '1' } } })

    const data = await uploadFile(UploadMediaDocument, { file, collection: 'library' })

    expect(data).toEqual({ uploadMedia: { id: '1' } })
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit]
    const form = init.body as FormData
    const operations = JSON.parse(form.get('operations') as string)
    expect(operations.query).toContain('mutation UploadMedia')
    expect(operations.variables).toEqual({ collection: 'library', file: null })
    expect(JSON.parse(form.get('map') as string)).toEqual({ '0': ['variables.file'] })
    expect((form.get('0') as File).name).toBe('foto.png')
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok')
    // The browser must set the multipart boundary itself.
    expect(Object.keys(init.headers as object)).not.toContain('Content-Type')
  })

  it('turns API error codes into friendly Spanish messages', async () => {
    stubFetch({ errors: [{ message: 'x', extensions: { code: 'UNSUPPORTED_MEDIA_TYPE' } }] })

    await expect(uploadFile(UploadMediaDocument, { file, collection: null })).rejects.toMatchObject({
      code: 'UNSUPPORTED_MEDIA_TYPE',
      message: expect.stringContaining('Tipo de archivo no permitido'),
    })
  })

  it('ends the session when the token is rejected', async () => {
    setToken('stale')
    stubFetch({ errors: [{ message: 'Unauthenticated.' }] })

    await expect(uploadFile(UploadMediaDocument, { file, collection: null })).rejects.toBeInstanceOf(UploadError)
    expect(getToken()).toBeNull()
  })

  it('reports network failures without leaking internals', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')))

    await expect(uploadFile(UploadMediaDocument, { file, collection: null })).rejects.toMatchObject({ code: 'NETWORK' })
  })
})
