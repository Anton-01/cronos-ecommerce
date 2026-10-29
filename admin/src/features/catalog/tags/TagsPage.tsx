import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { AdminTagsDocument, CreateTagDocument, DeleteTagDocument, UpdateTagDocument, type AdminTagsQuery } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'
import { useDebouncedValue } from '@/features/users/useDebouncedValue'

import { LocaleInputs, PageHeader, useSupportedLocales } from '../shared'

type Tag = AdminTagsQuery['adminTags'][number]

export function TagsPage() {
  const { can } = useAuth()
  const canManage = can('catalog.manage')
  const [search, setSearch] = useState('')
  const debounced = useDebouncedValue(search.trim())
  const { data, loading } = useQuery(AdminTagsDocument, { variables: { search: debounced || null } })
  const { defaultLocale } = useSupportedLocales()
  const [editing, setEditing] = useState<{ tag: Tag | null } | null>(null)
  const [remove] = useMutation(DeleteTagDocument, { refetchQueries: ['AdminTags'] })

  const destroy = async (tag: Tag) => {
    try {
      await remove({ variables: { id: tag.id } })
      toast.success('Etiqueta eliminada')
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <div className="max-w-2xl space-y-4">
      <PageHeader title="Etiquetas" description="Agrupan productos de forma transversal (p. ej. «Navidad», «Boda»)." actions={canManage && <Button onClick={() => setEditing({ tag: null })}><Plus /> Nueva etiqueta</Button>} />
      <Input type="search" aria-label="Buscar etiquetas" placeholder="Buscar…" value={search} onChange={(e) => setSearch(e.target.value)} />
      {loading && !data && <Skeleton className="h-32 w-full" />}
      <ul className="divide-y rounded-xl border bg-card">
        {data?.adminTags.map((tag) => (
          <li key={tag.id} className="flex items-center gap-2 px-3 py-2">
            <span className="flex-1 font-medium">{tag.translations.find((t) => t.locale === defaultLocale)?.name ?? tag.translations[0]?.name}</span>
            <span className="text-xs text-muted-foreground">{tag.translations.find((t) => t.locale === defaultLocale)?.slug}</span>
            {canManage && (
              <>
                <Button size="sm" variant="ghost" onClick={() => setEditing({ tag })}>
                  Editar
                </Button>
                <Button size="sm" variant="ghost" onClick={() => void destroy(tag)}>
                  Eliminar
                </Button>
              </>
            )}
          </li>
        ))}
        {data && data.adminTags.length === 0 && <li className="py-8 text-center text-sm text-muted-foreground">Sin etiquetas.</li>}
      </ul>
      <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
        <DialogContent>{editing && <TagForm key={editing.tag?.id ?? 'new'} tag={editing.tag} onClose={() => setEditing(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function TagForm({ tag, onClose }: { tag: Tag | null; onClose: () => void }) {
  const { locales, defaultLocale } = useSupportedLocales()
  const [names, setNames] = useState<Record<string, string>>(() => Object.fromEntries((tag?.translations ?? []).map((t) => [t.locale, t.name])))
  const [create] = useMutation(CreateTagDocument, { refetchQueries: ['AdminTags'] })
  const [update] = useMutation(UpdateTagDocument, { refetchQueries: ['AdminTags'] })

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    const translations = locales.filter((l) => (names[l.code] ?? '').trim()).map((l) => ({ locale: l.code, name: names[l.code]!.trim(), slug: tag?.translations.find((t) => t.locale === l.code)?.slug }))
    try {
      if (tag) await update({ variables: { id: tag.id, translations } })
      else await create({ variables: { translations } })
      toast.success('Etiqueta guardada')
      onClose()
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4">
      <DialogHeader>
        <DialogTitle>{tag ? 'Editar etiqueta' : 'Nueva etiqueta'}</DialogTitle>
        <DialogDescription>Un nombre por idioma; el idioma principal es obligatorio.</DialogDescription>
      </DialogHeader>
      <LocaleInputs label="Nombre" locales={locales} values={names} onChange={setNames} required />
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" disabled={!(names[defaultLocale] ?? '').trim()}>
          Guardar
        </Button>
      </div>
    </form>
  )
}
