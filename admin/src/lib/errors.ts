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
  CONFIRMATION_REQUIRED: 'Escribe la frase de confirmación exactamente: PASAR A PRODUCCION.',
  CREDENTIALS_INCOMPLETE: 'Faltan credenciales de ese modo. Complétalas antes de continuar.',
  CREDENTIAL_ENVIRONMENT_MISMATCH: 'Esa credencial no corresponde a este ambiente (¿pegaste una llave de producción en sandbox o al revés?).',
  STALE_VERSION: 'Alguien más cambió este anaquel mientras lo mirabas. Vuelve a abrirlo y revisa la cantidad.',
  INSUFFICIENT_STOCK: 'No hay suficientes existencias para esa operación.',
  INVALID_STOCK_CODE: 'Ese código no es válido. Revisa la etiqueta o escríbelo de nuevo.',
  NOT_A_SYSTEM_USER: 'Solo se puede elegir a personas activas del sistema.',
  SELF_APPROVAL: 'Otra persona tiene que aprobar tu propia solicitud.',
  ALREADY_DECIDED: 'Alguien ya resolvió esto.',
  MORE_THAN_PENDING: 'Eso es más de lo que falta por surtir en el pedido.',
  NOTHING_TO_PICK: 'Ese pedido no tiene pendiente este producto en este almacén.',
  LOCATION_NOT_PICKABLE: 'De esa ubicación no se surte: primero acomoda la pieza en un anaquel.',
  LOCATION_NOT_EMPTY: 'Todavía hay existencias aquí o debajo; muévelas antes de desactivar.',
  LOCATION_EXISTS: 'Ya existe una ubicación con ese código en este almacén (los códigos no se reutilizan).',
  LOCATION_TOO_DEEP: 'El árbol del almacén no puede tener tantos niveles.',
  LOCATION_CYCLE: 'Una ubicación no puede quedar dentro de sí misma.',
  COUNTS_IN_REVIEW: 'Resuelve primero los conteos que esperan revisión.',
  COUNT_CLOSED: 'Ese conteo ya está cerrado.',
  POLICY_WOULD_DISABLE_APPROVAL: 'Debe quedar activo al menos un límite de aprobación.',
  LOW_CONTRAST: 'Los colores del QR necesitan más contraste para leerse desde el anaquel.',
  INVALID_LABEL_STYLE: 'Ese estilo de etiqueta no es válido. Revisa los valores.',
  GATEWAY_UNAVAILABLE: 'El proveedor no responde ahora. Inténtalo en unos minutos.',
}

export function friendlyMessage(error: ApiError): string {
  if (error.code && FRIENDLY[error.code]) return FRIENDLY[error.code]!
  // Settings errors name the offending key in brackets: "Invalid value for [contact.phone]: ..."
  const key = /\[([^\]]+)\]/.exec(error.message)?.[1]
  if (error.code === 'INVALID_SETTING') return `Valor no válido para «${key ?? 'la configuración'}». Revisa el formato.`
  if (error.code === 'UNKNOWN_SETTING') return `La clave «${key ?? ''}» no existe.`
  if (error.code === 'MEDIA_NOT_FOUND') return 'El archivo ya no existe.'
  if (error.code === 'UNKNOWN_LOCALE') return 'Idioma no soportado.'
  if (error.message.startsWith('Rate limit')) return 'Demasiados intentos. Espera un momento e inténtalo de nuevo.'
  return error.message
}
