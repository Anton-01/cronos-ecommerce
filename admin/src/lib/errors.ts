import { CombinedGraphQLErrors } from '@apollo/client/errors'

export interface ApiError {
  code: string | null
  message: string
  fields: Record<string, string[]>
}

/** Normalises any Apollo error into something the UI can render. */
export function parseApiError(error: unknown): ApiError {
  if (CombinedGraphQLErrors.is(error)) {
    const first = error.errors[0]
    const validation = first?.extensions?.validation as Record<string, string[]> | undefined
    return {
      code: (first?.extensions?.code as string | undefined) ?? null,
      message: first?.message ?? 'Unexpected error',
      fields: validation ?? {},
    }
  }
  if (error instanceof Error) {
    return { code: 'NETWORK', message: 'No se pudo conectar con el servidor.', fields: {} }
  }
  return { code: null, message: 'Unexpected error', fields: {} }
}

const FRIENDLY: Record<string, string> = {
  INVALID_CREDENTIALS: 'Correo o contraseña incorrectos.',
  ACCOUNT_DISABLED: 'Esta cuenta está deshabilitada.',
  NETWORK: 'No se pudo conectar con el servidor.',
}

export function friendlyMessage(error: ApiError): string {
  if (error.code && FRIENDLY[error.code]) return FRIENDLY[error.code]!
  if (error.message.startsWith('Rate limit')) return 'Demasiados intentos. Espera un momento e inténtalo de nuevo.'
  return error.message
}
