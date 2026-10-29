import { useQuery } from '@apollo/client/react'
import { useEffect, useState } from 'react'

import { MediaLibraryDocument, type MediaKind } from '@/graphql/generated'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'

type Cursor = { after: string | null; start: number }
const FIRST_PAGE: Cursor = { after: null, start: 0 }

/** Search + kind filter + cursor pagination, with polling while any file is still being processed. */
export function useMediaLibrary({ pageSize = 24, kind = null }: { pageSize?: number; kind?: MediaKind | null } = {}) {
  const [search, setSearch] = useState('')
  const [kindFilter, setKindFilter] = useState<MediaKind | null>(kind)
  const debounced = useDebouncedValue(search.trim())
  const [paging, setPaging] = useState<{ key: string; cursors: Cursor[] }>({ key: '', cursors: [FIRST_PAGE] })

  // Any change of the result set invalidates the cursors collected so far.
  const resultKey = `${debounced}|${kindFilter}`
  const cursors = paging.key === resultKey ? paging.cursors : [FIRST_PAGE]
  const current = cursors[cursors.length - 1] ?? FIRST_PAGE

  const { data, previousData, error, loading, refetch, startPolling, stopPolling } = useQuery(MediaLibraryDocument, {
    variables: { first: pageSize, after: current.after, search: debounced || null, kind: kindFilter },
    fetchPolicy: 'cache-and-network',
  })

  const connection = (data ?? previousData)?.media
  const items = connection?.edges.map((e) => e.node) ?? []
  const processing = items.some((i) => i.status === 'PENDING' || i.status === 'PROCESSING')

  // Thumbnails are produced in the background: poll only while something is still pending.
  useEffect(() => {
    if (processing) startPolling(2000)
    else stopPolling()
    return stopPolling
  }, [processing, startPolling, stopPolling])

  const next = () => {
    const cursor = connection?.pageInfo.endCursor
    if (cursor) setPaging({ key: resultKey, cursors: [...cursors, { after: cursor, start: current.start + items.length }] })
  }
  const previous = () => setPaging({ key: resultKey, cursors: cursors.slice(0, -1) })

  return {
    items,
    processing,
    total: connection?.pageInfo.total ?? 0,
    from: items.length === 0 ? 0 : current.start + 1,
    to: current.start + items.length,
    hasNext: connection?.pageInfo.hasNextPage ?? false,
    hasPrevious: cursors.length > 1,
    next,
    previous,
    search,
    setSearch,
    kind: kindFilter,
    setKind: setKindFilter,
    loading,
    error,
    refetch,
  }
}
