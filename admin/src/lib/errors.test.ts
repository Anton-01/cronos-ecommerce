import { describe, expect, it } from 'vitest'

import { friendlyMessage, type ApiError } from './errors'

const err = (code: string | null, message: string): ApiError => ({ code, message, fields: {} })

describe('friendlyMessage', () => {
  it('translates known API codes', () => {
    expect(friendlyMessage(err('INVALID_CREDENTIALS', 'x'))).toBe('Correo o contraseña incorrectos.')
    expect(friendlyMessage(err('MEDIA_NOT_FOUND', 'x'))).toBe('El archivo ya no existe.')
  })

  it('names the offending setting', () => {
    expect(friendlyMessage(err('INVALID_SETTING', 'Invalid value for [contact.phone]: the format is invalid.'))).toBe('Valor no válido para «contact.phone». Revisa el formato.')
  })

  it('explains throttling and passes unknown messages through', () => {
    expect(friendlyMessage(err(null, 'Rate limit for Mutation.login exceeded.'))).toContain('Demasiados intentos')
    expect(friendlyMessage(err('SOMETHING', 'Plain message'))).toBe('Plain message')
  })
})
