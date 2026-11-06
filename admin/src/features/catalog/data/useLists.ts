import { useQuery } from '@apollo/client/react'
import { useMemo } from 'react'

import { AdminAttributesDocument, AdminCatalogOptionsDocument, type AdminCatalogOptionsQuery } from '@/graphql/generated'

import { useSupportedLocales } from '../shared'
import { labelOf } from './specs'

export type Option = AdminCatalogOptionsQuery['adminCatalogOptions'][number]

/** Every short list the data sheet is filled from, plus the colours (an attribute), ready for the selects. */
export function useLists() {
  const { defaultLocale } = useSupportedLocales()
  const packaging = useQuery(AdminCatalogOptionsDocument, { variables: { catalog: 'packaging_type', includeInactive: false } })
  const units = useQuery(AdminCatalogOptionsDocument, { variables: { catalog: 'unit', includeInactive: false } })
  const weight = useQuery(AdminCatalogOptionsDocument, { variables: { catalog: 'weight_unit', includeInactive: false } })
  const length = useQuery(AdminCatalogOptionsDocument, { variables: { catalog: 'length_unit', includeInactive: false } })
  const attributes = useQuery(AdminAttributesDocument)

  return useMemo(() => {
    const byCatalog: Record<string, Option[]> = {
      packaging_type: packaging.data?.adminCatalogOptions ?? [],
      unit: units.data?.adminCatalogOptions ?? [],
      weight_unit: weight.data?.adminCatalogOptions ?? [],
      length_unit: length.data?.adminCatalogOptions ?? [],
    }
    const attrs = attributes.data?.adminAttributes ?? []
    return {
      defaultLocale,
      byCatalog,
      attributes: attrs,
      attribute: (code: string) => attrs.find((a) => a.code === code),
      colors: attrs.find((a) => a.code === 'color')?.values ?? [],
      label: (o: Option, plural = false) => (plural ? (((o.meta as { plural?: Record<string, string> } | null)?.plural ?? {})[defaultLocale] ?? labelOf(o.labels, defaultLocale)) : labelOf(o.labels, defaultLocale)),
      loading: packaging.loading || units.loading || weight.loading || length.loading || attributes.loading,
    }
  }, [packaging.data, units.data, weight.data, length.data, attributes.data, defaultLocale, packaging.loading, units.loading, weight.loading, length.loading, attributes.loading])
}

export type Lists = ReturnType<typeof useLists>
