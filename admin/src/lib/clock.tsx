import { useQuery } from '@apollo/client/react'
import { createContext, useContext, useMemo } from 'react'

import { ClientContextDocument } from '@/graphql/generated'

export interface Clock {
  /** IANA timezone decided by the server from the caller's IP; undefined until known (the browser's own is used meanwhile). */
  timezone: string | undefined
  utcOffset: string | undefined
  country: string | null | undefined
  /** "1 jun 2026, 12:30" on the IP's clock. */
  formatDateTime: (value: string | null | undefined) => string
  formatDate: (value: string | null | undefined) => string
  /** "hora de America/Mexico_City (UTC-06:00)": what to print next to date inputs. */
  label: string
}

export function makeClock(timezone?: string, utcOffset?: string, country?: string | null): Clock {
  const format = (options: Intl.DateTimeFormatOptions) => (value: string | null | undefined) =>
    value ? new Intl.DateTimeFormat('es-MX', { timeZone: timezone, ...options }).format(new Date(value)) : ''

  return {
    timezone,
    utcOffset,
    country,
    formatDateTime: format({ dateStyle: 'medium', timeStyle: 'short' }),
    formatDate: format({ dateStyle: 'medium' }),
    label: timezone ? `hora de ${timezone.replace(/_/g, ' ')} (UTC${utcOffset ?? ''})` : 'hora local',
  }
}

const ClockContext = createContext<Clock>(makeClock())

/** Every date in the API already arrives on the IP's clock; this tells the screens which one that is. */
export function ClockProvider({ children }: { children: React.ReactNode }) {
  const { data } = useQuery(ClientContextDocument)
  const ctx = data?.clientContext
  const clock = useMemo(() => makeClock(ctx?.timezone, ctx?.utcOffset, ctx?.country), [ctx?.timezone, ctx?.utcOffset, ctx?.country])

  return <ClockContext.Provider value={clock}>{children}</ClockContext.Provider>
}

export const useClock = () => useContext(ClockContext)
