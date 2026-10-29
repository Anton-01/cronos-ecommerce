import { describe, expect, it } from 'vitest'

import { changedCredentials, matchesGoLivePhrase, needsAttention, statusLabel } from './phrase'

describe('go-live phrase', () => {
  it('ignores case, accents and extra spaces', () => {
    expect(matchesGoLivePhrase('pasar a producción')).toBe(true)
    expect(matchesGoLivePhrase('  PASAR   A   PRODUCCION ')).toBe(true)
  })

  it('rejects anything else', () => {
    expect(matchesGoLivePhrase('')).toBe(false)
    expect(matchesGoLivePhrase('pasar a prod')).toBe(false)
    expect(matchesGoLivePhrase('sí')).toBe(false)
  })
})

describe('credentials form', () => {
  it('sends only what was typed so stored secrets are kept', () => {
    expect(changedCredentials({ secret_key: ' sk_test_x ', publishable_key: '', webhook_secret: '   ' })).toEqual({ secret_key: 'sk_test_x' })
  })
})

describe('status labels', () => {
  it('names known statuses and passes unknown ones through', () => {
    expect(statusLabel('requires_action')).toBe('Esperando al cliente')
    expect(statusLabel('weird')).toBe('weird')
  })

  it('flags the ones that need a person', () => {
    expect(needsAttention('dead')).toBe(true)
    expect(needsAttention('captured')).toBe(false)
  })
})
