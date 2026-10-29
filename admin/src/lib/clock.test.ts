import { describe, expect, it } from 'vitest'

import { makeClock } from './clock'

describe('makeClock', () => {
  const instant = '2030-06-10T18:00:00Z'

  it('shows the same instant on the clock of the IP, not the browser', () => {
    expect(makeClock('America/Mexico_City', '-06:00', 'MX').formatDateTime(instant)).toContain('12:00')
    expect(makeClock('America/Los_Angeles', '-07:00', 'US').formatDateTime(instant)).toContain('11:00')
  })

  it('names the timezone next to date inputs and tolerates missing values', () => {
    const clock = makeClock('America/Mexico_City', '-06:00', 'MX')
    expect(clock.label).toBe('hora de America/Mexico City (UTC-06:00)')
    expect(clock.formatDateTime(null)).toBe('')
    expect(makeClock().label).toBe('hora local')
  })
})
