import { useQuery } from '@apollo/client/react'
import type { SortingState } from '@tanstack/react-table'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { UsersDocument, type UserRowFragment } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { UserDialog } from './UserDialog'
import { UsersTable } from './UsersTable'
import { useDebouncedValue } from './useDebouncedValue'

const PAGE_SIZES = [10, 25, 50, 100]
type Cursor = { after: string | null; start: number }
const FIRST_PAGE: Cursor = { after: null, start: 0 }
const COLUMN_BY_ID = { email: 'EMAIL', createdAt: 'CREATED_AT' } as const

export function UsersPage() {
  const [search, setSearch] = useState('')
  const debouncedSearch = useDebouncedValue(search.trim())
  const [sorting, setSorting] = useState<SortingState>([{ id: 'createdAt', desc: true }])
  const [pageSize, setPageSize] = useState(25)
  // Cursor pagination: each entry opens one page — its "after" cursor and how many rows precede it.
  const [paging, setPaging] = useState<{ key: string; cursors: Cursor[] }>({ key: '', cursors: [FIRST_PAGE] })
  const [selectedId, setSelectedId] = useState<string | null>(null)

  const sort = sorting[0]
  const column = sort ? COLUMN_BY_ID[sort.id as keyof typeof COLUMN_BY_ID] : undefined

  // Any change of the result set (search, sort, page size) invalidates the cursors collected so far.
  const resultKey = `${debouncedSearch}|${sort?.id}|${sort?.desc}|${pageSize}`
  const cursors = paging.key === resultKey ? paging.cursors : [FIRST_PAGE]
  const setCursors = (update: (previous: Cursor[]) => Cursor[]) => setPaging({ key: resultKey, cursors: update(cursors) })
  const page = cursors.length - 1
  const current = cursors[page] ?? FIRST_PAGE

  const { data, previousData, error, loading, refetch } = useQuery(UsersDocument, {
    variables: {
      first: pageSize,
      after: current.after,
      search: debouncedSearch || null,
      orderBy: column ? [{ column, order: sort?.desc ? 'DESC' : 'ASC' }] : null,
    },
    fetchPolicy: 'cache-and-network',
  })

  const connection = (data ?? previousData)?.users
  const rows = connection?.edges.map((edge) => edge.node) ?? []
  const total = connection?.pageInfo.total ?? 0
  const from = rows.length === 0 ? 0 : current.start + 1
  const to = current.start + rows.length
  const selected: UserRowFragment | null = rows.find((u) => u.id === selectedId) ?? null

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Usuarios</h1>
          <p className="text-sm text-muted-foreground">Clientes y equipo registrados en la plataforma.</p>
        </div>
        <Input
          type="search"
          aria-label="Buscar por correo"
          placeholder="Buscar por correo…"
          className="w-full sm:w-72"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
      </div>

      {error && (
        <div role="alert" className="flex items-center justify-between rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {friendlyMessage(parseApiError(error))}
          <Button size="sm" variant="outline" onClick={() => void refetch()}>
            Reintentar
          </Button>
        </div>
      )}

      <div className="rounded-xl border bg-card">
        <UsersTable rows={rows} loading={loading} sorting={sorting} onSortingChange={setSorting} onSelect={(u) => setSelectedId(u.id)} pageSize={pageSize} />
        <div className="flex flex-wrap items-center justify-between gap-3 border-t px-3 py-2 text-sm">
          <span className="text-muted-foreground" aria-live="polite">
            {total === 0 ? 'Sin resultados' : `${from}–${to} de ${total}`}
          </span>
          <div className="flex items-center gap-2">
            <label className="flex items-center gap-2 text-muted-foreground">
              Filas
              <select
                className="h-8 rounded-md border bg-background px-2 text-foreground"
                value={pageSize}
                onChange={(e) => setPageSize(Number(e.target.value))}
              >
                {PAGE_SIZES.map((size) => (
                  <option key={size} value={size}>
                    {size}
                  </option>
                ))}
              </select>
            </label>
            <Button size="sm" variant="outline" disabled={page === 0} onClick={() => setCursors((c) => c.slice(0, -1))}>
              Anterior
            </Button>
            <Button
              size="sm"
              variant="outline"
              disabled={!connection?.pageInfo.hasNextPage}
              onClick={() => {
                const next = connection?.pageInfo.endCursor
                if (next) setCursors((c) => [...c, { after: next, start: current.start + rows.length }])
              }}
            >
              Siguiente
            </Button>
          </div>
        </div>
      </div>

      <UserDialog user={selected} onClose={() => setSelectedId(null)} />
    </div>
  )
}
