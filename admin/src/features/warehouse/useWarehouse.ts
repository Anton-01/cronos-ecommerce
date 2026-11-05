import { useQuery } from '@apollo/client/react'
import { useCallback, useMemo, useState } from 'react'

import { WhWarehousesDocument } from '@/graphql/generated'

const KEY = 'cronos.warehouse'

/** The warehouse the person is working in: remembered on this device, the default one the first time. */
export function useWarehouse() {
  const { data, loading } = useQuery(WhWarehousesDocument)
  const [chosen, setChosen] = useState<string | null>(() => {
    try {
      return localStorage.getItem(KEY)
    } catch {
      return null
    }
  })
  const warehouses = useMemo(() => (data?.whWarehouses ?? []).filter((w) => w.isActive), [data])
  const current = warehouses.find((w) => w.id === chosen) ?? warehouses.find((w) => w.isDefault) ?? warehouses[0] ?? null

  const choose = useCallback((id: string) => {
    setChosen(id)
    try {
      localStorage.setItem(KEY, id)
    } catch {
      // private mode: the choice just lasts for this visit
    }
  }, [])

  return { warehouses, current, choose, loading }
}
