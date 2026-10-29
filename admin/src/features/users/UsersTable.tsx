import { flexRender, getCoreRowModel, useReactTable, type ColumnDef, type OnChangeFn, type SortingState } from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ChevronsUpDown } from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { UserRowFragment } from '@/graphql/generated'

const dateFormat = new Intl.DateTimeFormat('es-MX', { dateStyle: 'medium' })

const columns: ColumnDef<UserRowFragment>[] = [
  {
    id: 'email',
    header: 'Correo',
    accessorKey: 'email',
    cell: ({ row }) => {
      const p = row.original.profile
      const name = [p?.firstName, p?.lastName].filter(Boolean).join(' ')
      return (
        <div className="min-w-0">
          <div className="truncate font-medium">{row.original.email}</div>
          {name && <div className="truncate text-xs text-muted-foreground">{name}</div>}
        </div>
      )
    },
  },
  {
    id: 'roles',
    header: 'Roles',
    enableSorting: false,
    cell: ({ row }) => (
      <div className="flex flex-wrap gap-1">
        {row.original.roles.map((role) => (
          <Badge key={role} variant="secondary">
            {role}
          </Badge>
        ))}
      </div>
    ),
  },
  {
    id: 'status',
    header: 'Estado',
    enableSorting: false,
    cell: ({ row }) => (
      <div className="flex flex-wrap gap-1">
        <Badge variant={row.original.isActive ? 'success' : 'destructive'}>{row.original.isActive ? 'Activo' : 'Deshabilitado'}</Badge>
        {!row.original.emailVerifiedAt && <Badge variant="outline">Sin verificar</Badge>}
      </div>
    ),
  },
  {
    id: 'createdAt',
    header: 'Alta',
    accessorKey: 'createdAt',
    cell: ({ row }) => dateFormat.format(new Date(row.original.createdAt)),
  },
]

interface Props {
  rows: UserRowFragment[]
  loading: boolean
  sorting: SortingState
  onSortingChange: OnChangeFn<SortingState>
  onSelect: (user: UserRowFragment) => void
  pageSize: number
}

export function UsersTable({ rows, loading, sorting, onSortingChange, onSelect, pageSize }: Props) {
  // TanStack Table returns unstable function identities; the table is not memoized by design.
  // eslint-disable-next-line react-hooks/incompatible-library
  const table = useReactTable({
    data: rows,
    columns,
    state: { sorting },
    onSortingChange,
    getCoreRowModel: getCoreRowModel(),
    getRowId: (row) => row.id,
    // Sorting and paging are executed by the API, never in the browser.
    manualSorting: true,
    manualPagination: true,
    enableSortingRemoval: false,
  })

  return (
    <Table>
      <TableHeader>
        {table.getHeaderGroups().map((group) => (
          <TableRow key={group.id} className="hover:bg-transparent">
            {group.headers.map((header) => {
              const sorted = header.column.getIsSorted()
              return (
                <TableHead key={header.id} aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}>
                  {header.column.getCanSort() ? (
                    <button type="button" className="inline-flex items-center gap-1 uppercase hover:text-foreground" onClick={header.column.getToggleSortingHandler()}>
                      {flexRender(header.column.columnDef.header, header.getContext())}
                      {sorted === 'asc' ? <ArrowUp className="size-3" /> : sorted === 'desc' ? <ArrowDown className="size-3" /> : <ChevronsUpDown className="size-3 opacity-50" />}
                    </button>
                  ) : (
                    flexRender(header.column.columnDef.header, header.getContext())
                  )}
                </TableHead>
              )
            })}
          </TableRow>
        ))}
      </TableHeader>
      <TableBody>
        {loading && rows.length === 0
          ? Array.from({ length: Math.min(pageSize, 8) }, (_, i) => (
              <TableRow key={i} className="hover:bg-transparent">
                {columns.map((_, j) => (
                  <TableCell key={j}>
                    <Skeleton className="h-5 w-full max-w-40" />
                  </TableCell>
                ))}
              </TableRow>
            ))
          : table.getRowModel().rows.map((row) => (
              <TableRow
                key={row.id}
                tabIndex={0}
                className="cursor-pointer focus-visible:bg-muted/50 focus-visible:outline-none"
                onClick={() => onSelect(row.original)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') onSelect(row.original)
                }}
              >
                {row.getVisibleCells().map((cell) => (
                  <TableCell key={cell.id}>{flexRender(cell.column.columnDef.cell, cell.getContext())}</TableCell>
                ))}
              </TableRow>
            ))}
        {!loading && rows.length === 0 && (
          <TableRow className="hover:bg-transparent">
            <TableCell colSpan={columns.length} className="py-10 text-center text-muted-foreground">
              No se encontraron usuarios.
            </TableCell>
          </TableRow>
        )}
      </TableBody>
    </Table>
  )
}
