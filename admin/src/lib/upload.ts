import type { TypedDocumentNode } from '@apollo/client'
import { print } from 'graphql'

import { GRAPHQL_URL } from './apollo'
import { clearSession, getToken } from './session'

export class UploadError extends Error {
  constructor(
    message: string,
    readonly code: string | null,
  ) {
    super(message)
  }
}

const FRIENDLY: Record<string, string> = {
  UNSUPPORTED_MEDIA_TYPE: 'Tipo de archivo no permitido. Usa JPG, PNG, WebP, GIF o PDF.',
  MEDIA_TOO_LARGE: 'El archivo es demasiado grande.',
  INVALID_IMAGE: 'La imagen está dañada o es demasiado grande.',
}

/**
 * Sends a file with the GraphQL multipart request spec (the browser sets the boundary).
 * Apollo's HttpLink only speaks JSON, so uploads go through this thin helper.
 */
export async function uploadFile<TData, TVars extends { file: File }>(
  document: TypedDocumentNode<TData, TVars>,
  variables: TVars,
): Promise<TData> {
  const { file, ...rest } = variables
  const form = new FormData()
  form.append('operations', JSON.stringify({ query: print(document), variables: { ...rest, file: null } }))
  form.append('map', JSON.stringify({ '0': ['variables.file'] }))
  form.append('0', file, file.name)

  const token = getToken()
  let response: Response
  try {
    response = await fetch(GRAPHQL_URL, {
      method: 'POST',
      headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
      body: form,
    })
  } catch {
    throw new UploadError('No se pudo conectar con el servidor.', 'NETWORK')
  }

  const body = (await response.json().catch(() => null)) as {
    data?: TData | null
    errors?: { message: string; extensions?: { code?: string } }[]
  } | null

  const error = body?.errors?.[0]
  if (error) {
    if (error.message === 'Unauthenticated.') clearSession()
    const code = error.extensions?.code ?? null
    throw new UploadError((code && FRIENDLY[code]) || error.message, code)
  }
  if (!body?.data) throw new UploadError('Respuesta inesperada del servidor.', null)

  return body.data
}
