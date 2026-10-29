import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { AdminCategoriesDocument, AdminTagsDocument, UpdateProductDocument } from '@/graphql/generated'

import { useSupportedLocales } from '../shared'
import { type Product } from './tabs'
import { useRun } from './useRun'

export function OrganizationTab({ product, canEdit }: { product: Product; canEdit: boolean }) {
  const run = useRun()
  const { defaultLocale } = useSupportedLocales()
  const { data: cats } = useQuery(AdminCategoriesDocument)
  const { data: tagData } = useQuery(AdminTagsDocument, { variables: { search: null } })
  const [update, { loading }] = useMutation(UpdateProductDocument)
  const [categoryIds, setCategoryIds] = useState<string[]>(product.categoryIds)
  const [primary, setPrimary] = useState<string | null>(product.primaryCategoryId ?? null)
  const [tagIds, setTagIds] = useState<string[]>(product.tagIds)

  const toggle = (list: string[], id: string, on: boolean) => (on ? [...list, id] : list.filter((x) => x !== id))
  const name = (items: { locale: string; name: string }[]) => items.find((t) => t.locale === defaultLocale)?.name ?? items[0]?.name ?? ''

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Categorías</CardTitle>
          <CardDescription>Marca dónde aparece el producto y elige la categoría principal (la de las migas de pan).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-1.5">
          {cats?.adminCategories.length === 0 && <p className="text-sm text-muted-foreground">Aún no hay categorías. Créalas en Catálogo → Categorías.</p>}
          {cats?.adminCategories.map((c) => (
            <div key={c.id} className="flex items-center gap-2" style={{ paddingLeft: `${(c.depth - 1) * 1.25}rem` }}>
              <Checkbox
                id={`cat-${c.id}`}
                checked={categoryIds.includes(c.id)}
                disabled={!canEdit}
                onCheckedChange={(on) => {
                  const next = toggle(categoryIds, c.id, on === true)
                  setCategoryIds(next)
                  if (on !== true && primary === c.id) setPrimary(next[0] ?? null)
                  if (on === true && primary === null) setPrimary(c.id)
                }}
              />
              <Label htmlFor={`cat-${c.id}`} className="flex-1 font-normal">
                {name(c.translations)}
              </Label>
              {categoryIds.includes(c.id) && (
                <label className="flex items-center gap-1 text-xs text-muted-foreground">
                  <input type="radio" name="primary-category" checked={primary === c.id} disabled={!canEdit} onChange={() => setPrimary(c.id)} /> Principal
                </label>
              )}
            </div>
          ))}
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Etiquetas</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-x-5 gap-y-2">
          {tagData?.adminTags.length === 0 && <p className="text-sm text-muted-foreground">Aún no hay etiquetas.</p>}
          {tagData?.adminTags.map((t) => (
            <div key={t.id} className="flex items-center gap-2">
              <Checkbox id={`tag-${t.id}`} checked={tagIds.includes(t.id)} disabled={!canEdit} onCheckedChange={(on) => setTagIds(toggle(tagIds, t.id, on === true))} />
              <Label htmlFor={`tag-${t.id}`} className="font-normal">
                {name(t.translations)}
              </Label>
            </div>
          ))}
        </CardContent>
      </Card>
      {canEdit && (
        <div className="flex justify-end">
          <Button disabled={loading} onClick={() => void run(() => update({ variables: { id: product.id, input: { categoryIds, primaryCategoryId: categoryIds.length ? (primary ?? categoryIds[0]) : null, tagIds } } }), 'Organización guardada')}>
            Guardar organización
          </Button>
        </div>
      )}
    </div>
  )
}
