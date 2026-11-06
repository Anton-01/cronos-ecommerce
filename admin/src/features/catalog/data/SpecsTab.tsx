import { useApolloClient, useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Field } from '@/features/pricing/CurrenciesPage'
import { AdminProductDocument, AdminProductSpecsDocument, AdminProductTypesDocument, AdminSpecFieldsDocument, UpdateProductSpecsDocument, type AdminProductSpecsQuery } from '@/graphql/generated'

import { NativeSelect } from '../shared'
import { useRun } from '../products/useRun'
import type { Product } from '../products/tabs'
import { SpecForm } from './SpecForm'
import { fromSheet, labelOf, toPayload, type FieldDef, type States, type Values } from './specs'
import { useLists } from './useLists'

const SECTIONS: { group: string; title: string; text: string }[] = [
  { group: 'features', title: 'Características', text: 'Aplican a todas las variantes. Si no conoces un dato, márcalo como pendiente: no lo inventes.' },
  { group: 'sku', title: 'Especificaciones del SKU', text: 'Tipo de empaque, peso y medidas del producto.' },
  { group: 'unit', title: 'Unidad y presentación', text: 'En qué se cuenta y cómo viene empacado.' },
  { group: 'extra', title: 'Información adicional', text: 'Usos, observaciones y el código que tenía antes.' },
]

/** The data sheet of a product, with the state of every field and what is still missing to publish. */
export function SpecsTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const { data } = useQuery(AdminProductSpecsDocument, { variables: { productId: product.id }, fetchPolicy: 'network-only' })
  if (!data) return <p className="text-sm text-muted-foreground">Cargando la ficha…</p>
  return <Sheet key={JSON.stringify(data.adminProductSpecs.fields.map((f) => f.value))} product={product} canEdit={canEdit} sheet={data.adminProductSpecs} />
}

function Sheet({ product, canEdit, sheet }: { product: Product; canEdit: boolean; sheet: AdminProductSpecsQuery['adminProductSpecs'] }) {
  const run = useRun()
  const client = useApolloClient()
  const lists = useLists()
  const { data: defsData } = useQuery(AdminSpecFieldsDocument)
  const { data: typesData } = useQuery(AdminProductTypesDocument, { variables: { includeInactive: false } })
  const [save, { loading }] = useMutation(UpdateProductSpecsDocument)
  const initial = fromSheet(sheet.fields)
  const [values, setValues] = useState<Values>(initial.values)
  const [states, setStates] = useState<States>(initial.states)
  const [typeId, setTypeId] = useState(sheet.typeId ?? '')
  const defs: FieldDef[] = (defsData?.adminSpecFields ?? []).filter((d) => d.scope === 'product')
  const type = typesData?.adminProductTypes.find((t) => t.id === typeId)
  const required = type?.requiredFields ?? sheet.fields.filter((f) => f.required).map((f) => f.key)

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    const { specs, states: st } = toPayload(defs.map((d) => d.key), values, states)
    void run(
      () => save({ variables: { productId: product.id, specs, states: st, typeId: typeId || null, setType: true } }).then(() => client.refetchQueries({ include: [AdminProductDocument, AdminProductSpecsDocument] })),
      'Ficha guardada',
    )
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Tipo de producto</CardTitle>
          <CardDescription>El tipo define qué datos son obligatorios para publicar.</CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <NativeSelect aria-label="Tipo de producto" className="max-w-xs" value={typeId} disabled={!canEdit} onChange={(e) => setTypeId(e.target.value)}>
            <option value="">Sin tipo (nada es obligatorio)</option>
            {(typesData?.adminProductTypes ?? []).map((t) => (
              <option key={t.id} value={t.id}>
                {labelOf(t.names, lists.defaultLocale)}
              </option>
            ))}
          </NativeSelect>
          {sheet.missing.length > 0 && (
            <p role="status" className="text-sm text-amber-700 dark:text-amber-300">
              Falta para publicar: {sheet.missing.join(', ')}.
            </p>
          )}
        </CardContent>
      </Card>
      {SECTIONS.map((s) => (
        <Card key={s.group}>
          <CardHeader>
            <CardTitle className="text-base">{s.title}</CardTitle>
            <CardDescription>{s.text}</CardDescription>
          </CardHeader>
          <CardContent>
            <SpecForm
              fields={defs.filter((d) => d.group === s.group)}
              values={values}
              states={states}
              required={required}
              lists={lists}
              disabled={!canEdit}
              onChange={(k, v) => setValues((x) => ({ ...x, [k]: v }))}
              onState={(k, st) => setStates((x) => ({ ...x, [k]: x[k] === st ? 'pending' : st }))}
            />
          </CardContent>
        </Card>
      ))}
      {canEdit && (
        <Button type="submit" disabled={loading}>
          Guardar ficha
        </Button>
      )}
      {product.type === 'VARIABLE' && <VariantSheets product={product} canEdit={canEdit} />}
    </form>
  )
}

/** Supplier code and notes of each variation. */
function VariantSheets({ product, canEdit }: { product: Product; canEdit: boolean }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Datos de cada variante</CardTitle>
        <CardDescription>Código de proveedor y observaciones propias de cada variante.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {product.variations.map((v) => (
          <VariantRow key={v.id} id={v.id} sku={v.sku ?? v.id} canEdit={canEdit} />
        ))}
      </CardContent>
    </Card>
  )
}

function VariantRow({ id, sku, canEdit }: { id: string; sku: string; canEdit: boolean }) {
  const run = useRun()
  const { data } = useQuery(AdminProductSpecsDocument, { variables: { productId: id }, fetchPolicy: 'network-only' })
  const [save] = useMutation(UpdateProductSpecsDocument)
  const fields = data?.adminProductSpecs.fields ?? []
  const stored = (k: string) => (fields.find((f) => f.key === k)?.value as string | null) ?? ''
  const [code, setCode] = useState<string | null>(null)
  const [notes, setNotes] = useState<string | null>(null)

  return (
    <div className="grid items-end gap-3 sm:grid-cols-[8rem_1fr_1fr_auto]">
      <span className="pb-2 font-mono text-sm">{sku}</span>
      <Field id={`vc-${id}`} label="Código de proveedor">
        <Input id={`vc-${id}`} value={code ?? stored('supplier_code')} disabled={!canEdit} onChange={(e) => setCode(e.target.value)} />
      </Field>
      <Field id={`vn-${id}`} label="Observaciones de la variante">
        <Input id={`vn-${id}`} value={notes ?? stored('variant_notes')} disabled={!canEdit} onChange={(e) => setNotes(e.target.value)} />
      </Field>
      {canEdit && (
        <Button type="button" variant="outline" disabled={code === null && notes === null} onClick={() => void run(() => save({ variables: { productId: id, specs: { supplier_code: code ?? stored('supplier_code'), variant_notes: notes ?? stored('variant_notes') } } }), 'Variante guardada').then(() => { setCode(null); setNotes(null) })}>
          Guardar
        </Button>
      )}
    </div>
  )
}
