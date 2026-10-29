import { useMutation, useQuery } from '@apollo/client/react'
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import {
  AddAttributeValueDocument,
  AdminAttributesDocument,
  CreateAttributeDocument,
  DeleteAttributeDocument,
  DeleteAttributeValueDocument,
  type AdminAttributesQuery,
} from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { LocaleInputs, NativeSelect, PageHeader, useSupportedLocales } from '../shared'

type Attribute = AdminAttributesQuery['adminAttributes'][number]

const TYPE_LABELS: Record<string, string> = { select: 'Lista', color: 'Color', text: 'Texto', number: 'Número' }

export function AttributesPage() {
  const { can } = useAuth()
  const canManage = can('catalog.manage')
  const { data, loading } = useQuery(AdminAttributesDocument)
  const { defaultLocale } = useSupportedLocales()
  const [newAttribute, setNewAttribute] = useState(false)
  const [valueFor, setValueFor] = useState<Attribute | null>(null)
  const [removeAttribute] = useMutation(DeleteAttributeDocument, { refetchQueries: ['AdminAttributes'] })
  const [removeValue] = useMutation(DeleteAttributeValueDocument, { refetchQueries: ['AdminAttributes'] })

  const run = async (action: () => Promise<unknown>, ok: string) => {
    try {
      await action()
      toast.success(ok)
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }
  const label = (items: { locale: string; value: string | null }[]) => items.find((i) => i.locale === defaultLocale)?.value ?? items[0]?.value ?? ''

  return (
    <div className="max-w-3xl space-y-4">
      <PageHeader
        title="Atributos"
        description="Características como Color o Ancho. Las que definen variaciones permiten vender combinaciones."
        actions={canManage && <Button onClick={() => setNewAttribute(true)}><Plus /> Nuevo atributo</Button>}
      />
      {loading && !data && <Skeleton className="h-40 w-full" />}
      {data?.adminAttributes.map((a) => (
        <Card key={a.id}>
          <CardHeader className="flex-row items-center justify-between gap-2">
            <CardTitle className="flex items-center gap-2 text-base">
              {label(a.names)} <Badge variant="secondary">{TYPE_LABELS[a.type] ?? a.type}</Badge>
              <span className="font-mono text-xs font-normal text-muted-foreground">{a.code}</span>
              {a.inUse && <Badge variant="outline">En uso</Badge>}
            </CardTitle>
            {canManage && (
              <div className="flex gap-1">
                <Button size="sm" variant="outline" onClick={() => setValueFor(a)}>
                  + Valor
                </Button>
                <Button size="sm" variant="ghost" disabled={a.inUse} title={a.inUse ? 'Hay productos que lo usan' : undefined} onClick={() => void run(() => removeAttribute({ variables: { id: a.id } }), 'Atributo eliminado')}>
                  Eliminar
                </Button>
              </div>
            )}
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {a.values.length === 0 && <span className="text-sm text-muted-foreground">Sin valores todavía.</span>}
            {a.values.map((v) => (
              <span key={v.id} className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                {a.type === 'color' && typeof (v.meta as { hex?: string } | null)?.hex === 'string' && (
                  <span aria-hidden className="size-3.5 rounded-full border" style={{ backgroundColor: (v.meta as { hex: string }).hex }} />
                )}
                {label(v.labels)}
                {canManage && (
                  <button
                    type="button"
                    aria-label={`Quitar ${label(v.labels)}`}
                    disabled={v.inUse}
                    title={v.inUse ? 'Hay variaciones que lo usan' : undefined}
                    className="text-muted-foreground hover:text-destructive disabled:opacity-30"
                    onClick={() => void run(() => removeValue({ variables: { id: v.id } }), 'Valor eliminado')}
                  >
                    ×
                  </button>
                )}
              </span>
            ))}
          </CardContent>
        </Card>
      ))}

      <Dialog open={newAttribute} onOpenChange={setNewAttribute}>
        <DialogContent>{newAttribute && <AttributeForm onClose={() => setNewAttribute(false)} />}</DialogContent>
      </Dialog>
      <Dialog open={valueFor !== null} onOpenChange={(open) => !open && setValueFor(null)}>
        <DialogContent>{valueFor && <ValueForm key={valueFor.id} attribute={valueFor} onClose={() => setValueFor(null)} />}</DialogContent>
      </Dialog>
    </div>
  )
}

function AttributeForm({ onClose }: { onClose: () => void }) {
  const { locales, defaultLocale } = useSupportedLocales()
  const [code, setCode] = useState('')
  const [type, setType] = useState('select')
  const [names, setNames] = useState<Record<string, string>>({})
  const [create, { loading }] = useMutation(CreateAttributeDocument, { refetchQueries: ['AdminAttributes'] })

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      await create({ variables: { code, type, names: locales.filter((l) => (names[l.code] ?? '').trim()).map((l) => ({ locale: l.code, value: names[l.code]!.trim() })) } })
      toast.success('Atributo creado')
      onClose()
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4">
      <DialogHeader>
        <DialogTitle>Nuevo atributo</DialogTitle>
        <DialogDescription>El código es técnico y no cambia: minúsculas, números y guion bajo.</DialogDescription>
      </DialogHeader>
      <LocaleInputs label="Nombre" locales={locales} values={names} onChange={setNames} required />
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="attr-code">Código</Label>
          <Input id="attr-code" value={code} placeholder="color" onChange={(e) => setCode(e.target.value.toLowerCase())} />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="attr-type">Tipo</Label>
          <NativeSelect id="attr-type" value={type} onChange={(e) => setType(e.target.value)}>
            {Object.entries(TYPE_LABELS).map(([value, text]) => (
              <option key={value} value={value}>
                {text}
              </option>
            ))}
          </NativeSelect>
        </div>
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" disabled={loading || !code || !(names[defaultLocale] ?? '').trim()}>
          Crear
        </Button>
      </div>
    </form>
  )
}

function ValueForm({ attribute, onClose }: { attribute: Attribute; onClose: () => void }) {
  const { locales, defaultLocale } = useSupportedLocales()
  const [code, setCode] = useState('')
  const [hex, setHex] = useState('#c8285a')
  const [labels, setLabels] = useState<Record<string, string>>({})
  const [add, { loading }] = useMutation(AddAttributeValueDocument, { refetchQueries: ['AdminAttributes'] })

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    try {
      await add({
        variables: {
          attributeId: attribute.id,
          code,
          meta: attribute.type === 'color' ? { hex } : null,
          labels: locales.filter((l) => (labels[l.code] ?? '').trim()).map((l) => ({ locale: l.code, value: labels[l.code]!.trim() })),
        },
      })
      toast.success('Valor agregado')
      onClose()
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <form onSubmit={(e) => void submit(e)} className="space-y-4">
      <DialogHeader>
        <DialogTitle>Nuevo valor</DialogTitle>
        <DialogDescription>Para el atributo «{attribute.names[0]?.value}».</DialogDescription>
      </DialogHeader>
      <LocaleInputs label="Etiqueta" locales={locales} values={labels} onChange={setLabels} required />
      <div className="grid grid-cols-2 gap-3">
        <div className="space-y-1.5">
          <Label htmlFor="val-code">Código</Label>
          <Input id="val-code" value={code} placeholder="rojo" onChange={(e) => setCode(e.target.value.toLowerCase())} />
        </div>
        {attribute.type === 'color' && (
          <div className="space-y-1.5">
            <Label htmlFor="val-hex">Color</Label>
            <input id="val-hex" type="color" value={hex} onChange={(e) => setHex(e.target.value)} className="h-9 w-full cursor-pointer rounded-md border bg-background p-1" />
          </div>
        )}
      </div>
      <div className="flex justify-end gap-2">
        <Button type="button" variant="outline" onClick={onClose}>
          Cancelar
        </Button>
        <Button type="submit" disabled={loading || !code || !(labels[defaultLocale] ?? '').trim()}>
          Agregar
        </Button>
      </div>
    </form>
  )
}
