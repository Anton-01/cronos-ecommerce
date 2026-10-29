import { useMutation, useQuery } from '@apollo/client/react'
import { ChevronRight, Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import {
  AdminCategoriesDocument,
  AdminTaxClassesDocument,
  CreateCategoryDocument,
  DeleteCategoryDocument,
  MoveCategoryDocument,
  UpdateCategoryDocument,
  type AdminCategoriesQuery,
} from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { LocaleInputs, NativeSelect, PageHeader, useSupportedLocales } from '../shared'

type Category = AdminCategoriesQuery['adminCategories'][number]

const nameOf = (c: Category, locale: string) => c.translations.find((t) => t.locale === locale)?.name ?? c.translations[0]?.name ?? c.id

export function CategoriesPage() {
  const { can } = useAuth()
  const canManage = can('catalog.manage')
  const { data, loading } = useQuery(AdminCategoriesDocument)
  const { defaultLocale } = useSupportedLocales()
  const [editing, setEditing] = useState<{ category: Category | null; parentId: string | null } | null>(null)
  const [remove] = useMutation(DeleteCategoryDocument, { refetchQueries: ['AdminCategories'] })

  const categories = data?.adminCategories ?? []

  const destroy = async (c: Category) => {
    if (!window.confirm(`¿Eliminar «${nameOf(c, defaultLocale)}»?`)) return
    try {
      await remove({ variables: { id: c.id } })
      toast.success('Categoría eliminada')
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader
        title="Categorías"
        description="Árbol de hasta 5 niveles. Una categoría inactiva oculta también a sus hijas."
        actions={canManage && <Button onClick={() => setEditing({ category: null, parentId: null })}><Plus /> Nueva categoría</Button>}
      />
      {loading && !data && <Skeleton className="h-40 w-full" />}
      {!loading && categories.length === 0 && <p className="rounded-xl border border-dashed py-12 text-center text-sm text-muted-foreground">Aún no hay categorías.</p>}

      <ul className="divide-y rounded-xl border bg-card" aria-label="Árbol de categorías">
        {categories.map((c) => (
          <li key={c.id} className="flex items-center gap-2 px-3 py-2" style={{ paddingLeft: `${0.75 + (c.depth - 1) * 1.5}rem` }}>
            {c.depth > 1 && <ChevronRight className="size-3.5 shrink-0 text-muted-foreground" />}
            <span className="min-w-0 flex-1 truncate font-medium">{nameOf(c, defaultLocale)}</span>
            {!c.isActive && <Badge variant="destructive">Inactiva</Badge>}
            <span className="hidden text-xs text-muted-foreground sm:inline">{c.translations.find((t) => t.locale === defaultLocale)?.slug}</span>
            {canManage && (
              <div className="flex gap-1">
                {c.depth < 5 && (
                  <Button size="sm" variant="ghost" onClick={() => setEditing({ category: null, parentId: c.id })}>
                    + Hija
                  </Button>
                )}
                <Button size="sm" variant="ghost" onClick={() => setEditing({ category: c, parentId: c.parentId ?? null })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void destroy(c)}>
                  Eliminar
                </Button>
              </div>
            )}
          </li>
        ))}
      </ul>

      <CategoryDialog state={editing} categories={categories} onClose={() => setEditing(null)} />
    </div>
  )
}

function CategoryDialog({ state, categories, onClose }: { state: { category: Category | null; parentId: string | null } | null; categories: Category[]; onClose: () => void }) {
  return (
    <Dialog open={state !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>{state && <CategoryForm key={state.category?.id ?? `new-${state.parentId}`} {...state} categories={categories} onClose={onClose} />}</DialogContent>
    </Dialog>
  )
}

function CategoryForm({ category, parentId, categories, onClose }: { category: Category | null; parentId: string | null; categories: Category[]; onClose: () => void }) {
  const { locales, defaultLocale } = useSupportedLocales()
  const [names, setNames] = useState<Record<string, string>>(() => Object.fromEntries((category?.translations ?? []).map((t) => [t.locale, t.name])))
  const [active, setActive] = useState(category?.isActive ?? true)
  const [parent, setParent] = useState(parentId ?? '')
  const { can } = useAuth()
  const { data: taxClasses } = useQuery(AdminTaxClassesDocument, { skip: !can('pricing.view') })
  const [taxClassId, setTaxClassId] = useState(category?.taxClassId ?? '')
  const [create, { loading: creating }] = useMutation(CreateCategoryDocument, { refetchQueries: ['AdminCategories'] })
  const [update, { loading: updating }] = useMutation(UpdateCategoryDocument, { refetchQueries: ['AdminCategories'] })
  const [move] = useMutation(MoveCategoryDocument, { refetchQueries: ['AdminCategories'] })

  // A category cannot be moved under itself or its own branch.
  const parents = categories.filter((c) => !category || (c.id !== category.id && !c.path.startsWith(`${category.path}.`)))

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const translations = locales
      .filter((l) => (names[l.code] ?? '').trim() !== '')
      .map((l) => ({ locale: l.code, name: names[l.code]!.trim(), slug: category?.translations.find((t) => t.locale === l.code)?.slug }))
    try {
      if (category) {
        await update({ variables: { id: category.id, isActive: active, translations, ...(taxClasses ? { taxClassId: taxClassId || null } : {}) } })
        if ((parent || null) !== (category.parentId ?? null)) await move({ variables: { id: category.id, parentId: parent || null, position: 0 } })
      } else {
        await create({ variables: { parentId: parent || null, isActive: active, translations, taxClassId: taxClassId || null } })
      }
      toast.success('Categoría guardada')
      onClose()
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4">
      <DialogHeader>
        <DialogTitle>{category ? 'Editar categoría' : 'Nueva categoría'}</DialogTitle>
        <DialogDescription>El slug (URL) se genera a partir del nombre.</DialogDescription>
      </DialogHeader>
      <LocaleInputs label="Nombre" locales={locales} values={names} onChange={setNames} required />
      <div className="space-y-1.5">
        <Label htmlFor="cat-parent">Categoría superior</Label>
        <NativeSelect id="cat-parent" value={parent} onChange={(e) => setParent(e.target.value)}>
          <option value="">— Ninguna (nivel principal) —</option>
          {parents.map((c) => (
            <option key={c.id} value={c.id}>
              {'— '.repeat(c.depth - 1)}
              {nameOf(c, defaultLocale)}
            </option>
          ))}
        </NativeSelect>
      </div>
      {taxClasses && (
        <div className="space-y-1.5">
          <Label htmlFor="cat-tax">Clase de impuesto</Label>
          <NativeSelect id="cat-tax" value={taxClassId} onChange={(e) => setTaxClassId(e.target.value)}>
            <option value="">Predeterminada de la tienda</option>
            {taxClasses.adminTaxClasses.map((t) => (
              <option key={t.id} value={t.id}>
                {t.name}
              </option>
            ))}
          </NativeSelect>
          <p className="text-xs text-muted-foreground">La heredan los productos de esta categoría que no tengan una propia.</p>
        </div>
      )}
      <div className="flex items-center gap-2">
        <Checkbox id="cat-active" checked={active} onCheckedChange={(v) => setActive(v === true)} />
        <Label htmlFor="cat-active">Activa (visible en la tienda)</Label>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" disabled={creating || updating || !(names[defaultLocale] ?? '').trim()}>
          Guardar
        </Button>
      </div>
    </form>
  )
}
