import { useQuery } from '@apollo/client/react'

import { NativeSelect } from '@/features/catalog/shared'
import { WhLocationsDocument } from '@/graphql/generated'

/** The shelves of a warehouse as one list, indented by depth, so a person on a phone can pick one without a tree. */
export function LocationSelect({
  warehouseId,
  value,
  onChange,
  id,
  exclude,
  onlyPickable,
  allowEmpty,
  hideReception,
  emptyLabel = 'Recepción (por acomodar)',
}: {
  warehouseId: string
  value: string
  onChange: (id: string) => void
  id?: string
  exclude?: string
  onlyPickable?: boolean
  allowEmpty?: boolean
  /** The empty option already means the receiving area: do not list it twice. */
  hideReception?: boolean
  emptyLabel?: string
}) {
  const { data, loading } = useQuery(WhLocationsDocument, { variables: { warehouseId, includeInactive: false } })
  const all = data?.whLocations ?? []
  // depth-first order so a child sits right under its parent
  const children = new Map<string | null, typeof all>()
  for (const l of all) children.set(l.parentId, [...(children.get(l.parentId) ?? []), l])
  const ordered: typeof all = []
  const walk = (parent: string | null) => {
    for (const l of children.get(parent) ?? []) {
      ordered.push(l)
      walk(l.id)
    }
  }
  walk(null)

  return (
    <NativeSelect id={id} value={value} onChange={(e) => onChange(e.target.value)} disabled={loading} aria-busy={loading}>
      {allowEmpty && <option value="">{emptyLabel}</option>}
      {!allowEmpty && <option value="">Elige una ubicación…</option>}
      {ordered
        .filter((l) => l.id !== exclude && (!onlyPickable || l.isPickable) && !(hideReception && l.isSystem))
        .map((l) => (
          <option key={l.id} value={l.id}>
            {'  '.repeat(Math.max(0, l.depth - 1))}
            {l.name} ({l.code})
          </option>
        ))}
    </NativeSelect>
  )
}
