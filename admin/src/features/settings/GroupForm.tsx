import { useMutation } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { UpdateSettingsDocument, type AdminSettingsQuery } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { MediaField, type MediaValue } from './MediaField'

type Setting = AdminSettingsQuery['adminSettings'][number]
type Locale = AdminSettingsQuery['supportedLocales'][number]

/** What the form edits: one entry per key, translations or a single value. */
type Draft = Record<string, { value: unknown; translations: Record<string, string> }>

const idOf = (s: Pick<Setting, 'group' | 'key'>) => `${s.group}.${s.key}`

function initial(settings: Setting[], locales: Locale[]): Draft {
  return Object.fromEntries(
    settings.map((s) => [
      idOf(s),
      {
        value: s.value ?? null,
        translations: Object.fromEntries(locales.map((l) => [l.code, s.translations.find((t) => t.locale === l.code)?.value ?? ''])),
      },
    ]),
  )
}

const same = (a: Draft[string] | undefined, b: Draft[string] | undefined) => JSON.stringify(a) === JSON.stringify(b)

export function GroupForm({ settings, locales, canEdit }: { settings: Setting[]; locales: Locale[]; canEdit: boolean }) {
  const [baseline] = useState(() => initial(settings, locales))
  const [draft, setDraft] = useState<Draft>(baseline)
  const [save, { loading }] = useMutation(UpdateSettingsDocument)
  const dirty = settings.filter((s) => !same(draft[idOf(s)], baseline[idOf(s)]))

  const patch = (s: Setting, change: Partial<Draft[string]>) => setDraft((d) => ({ ...d, [idOf(s)]: { ...(d[idOf(s)] as Draft[string]), ...change } }))

  const submit = async (event: React.FormEvent) => {
    event.preventDefault()
    try {
      await save({
        variables: {
          input: dirty.map((s) => {
            const entry = draft[idOf(s)] as Draft[string]
            return s.translatable
              ? { group: s.group, key: s.key, translations: locales.map((l) => ({ locale: l.code, value: entry.translations[l.code] || null })) }
              : { group: s.group, key: s.key, value: s.type === 'media' ? ((entry.value as MediaValue | null)?.id ?? null) : entry.value === '' ? null : entry.value }
          }),
        },
      })
      toast.success('Configuración guardada')
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-6">
      {settings.map((s) => {
        const id = idOf(s)
        const entry = draft[id] as Draft[string]
        const field = `setting-${id}`

        return (
          <div key={id} className="space-y-2">
            <div className="flex items-center gap-2">
              <Label htmlFor={field}>{s.label}</Label>
              {!s.public && <Badge variant="outline">Privado</Badge>}
            </div>

            {s.translatable ? (
              <div className="space-y-2">
                {locales.map((l) => {
                  const common = {
                    id: l.code === locales[0]?.code ? field : `${field}-${l.code}`,
                    'aria-label': `${s.label} (${l.code})`,
                    value: entry.translations[l.code] ?? '',
                    disabled: !canEdit,
                    onChange: (e: React.ChangeEvent<HTMLInputElement & HTMLTextAreaElement>) => patch(s, { translations: { ...entry.translations, [l.code]: e.target.value } }),
                  }
                  return (
                    <div key={l.code} className="flex items-start gap-2">
                      <span className="mt-2 w-14 shrink-0 text-xs text-muted-foreground">{l.code}</span>
                      {s.type === 'text' ? <Textarea rows={4} {...common} /> : <Input maxLength={255} {...common} />}
                    </div>
                  )
                })}
              </div>
            ) : s.type === 'media' ? (
              <MediaField label={s.label} value={(entry.value as MediaValue | null) ?? null} disabled={!canEdit} onChange={(value) => patch(s, { value })} />
            ) : s.type === 'bool' ? (
              <Checkbox id={field} checked={entry.value === true} disabled={!canEdit} onCheckedChange={(checked) => patch(s, { value: checked === true })} />
            ) : (
              <Input
                id={field}
                type={s.type === 'email' ? 'email' : s.type === 'url' ? 'url' : s.type === 'int' ? 'number' : 'text'}
                value={(entry.value as string | number | null) ?? ''}
                disabled={!canEdit}
                onChange={(e) => patch(s, { value: e.target.value })}
              />
            )}
          </div>
        )
      })}

      {canEdit && (
        <div className="sticky bottom-0 -mx-1 flex items-center justify-between border-t bg-background/95 px-1 py-3 backdrop-blur">
          <span className="text-sm text-muted-foreground">{dirty.length === 0 ? 'Sin cambios' : `${dirty.length} ${dirty.length === 1 ? 'cambio' : 'cambios'} sin guardar`}</span>
          <Button type="submit" disabled={dirty.length === 0 || loading}>
            {loading ? 'Guardando…' : 'Guardar cambios'}
          </Button>
        </div>
      )}
    </form>
  )
}
