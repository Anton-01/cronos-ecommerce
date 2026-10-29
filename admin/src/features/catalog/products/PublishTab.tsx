import { useMutation } from '@apollo/client/react'
import { Check, Copy } from 'lucide-react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'
import { IssueProductPreviewDocument, PublishProductDocument, UnpublishProductDocument } from '@/graphql/generated'

import { NativeSelect } from '../shared'
import { STATUS_LABEL } from './ProductsPage'
import { type Product } from './tabs'
import { useClock } from '@/lib/clock'

import { useRun } from './useRun'

const STOREFRONT = import.meta.env.VITE_STOREFRONT_URL ?? 'http://localhost:3000'

export function PublishTab({ product }: { product: Product }) {
  const clock = useClock()
  const { can } = useAuth()
  const run = useRun()
  const [publish, { loading: publishing }] = useMutation(PublishProductDocument)
  const [unpublish, { loading: hiding }] = useMutation(UnpublishProductDocument)
  const [issue, { loading: issuing }] = useMutation(IssueProductPreviewDocument)
  const [ttl, setTtl] = useState('120')
  const [link, setLink] = useState<string | null>(null)
  const published = product.status === 'PUBLISHED'
  const ready = product.publishProblems.length === 0

  const makeLink = async () => {
    await run(async () => {
      const { data } = await issue({ variables: { id: product.id, ttlMinutes: Number(ttl) } })
      setLink(data ? `${STOREFRONT}${data.issueProductPreview.path}` : null)
    }, 'Enlace generado')
  }

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-base">
            Estado: <Badge variant={published ? 'success' : 'secondary'}>{STATUS_LABEL[product.status]}</Badge>
          </CardTitle>
          <CardDescription>{published ? 'Visible en la tienda desde ' + (product.publishedAt ? clock.formatDate(product.publishedAt) : 'ahora') + '.' : 'Solo tú lo ves hasta que lo publiques.'}</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {ready ? (
            <p className="flex items-center gap-2 text-sm text-success">
              <Check className="size-4" /> Cumple todo lo necesario para publicarse.
            </p>
          ) : (
            <div role="status">
              <p className="mb-1 text-sm font-medium">Falta para poder publicar:</p>
              <ul className="list-disc space-y-0.5 pl-5 text-sm text-muted-foreground">
                {product.publishProblems.map((p) => (
                  <li key={p}>{p}</li>
                ))}
              </ul>
            </div>
          )}
          {can('catalog.publish') && (
            <div className="flex flex-wrap gap-2">
              {!published && (
                <Button disabled={!ready || publishing} onClick={() => void run(() => publish({ variables: { id: product.id } }), 'Producto publicado')}>
                  Publicar
                </Button>
              )}
              {published && (
                <Button variant="outline" disabled={hiding} onClick={() => void run(() => unpublish({ variables: { id: product.id, status: 'INACTIVE' } }), 'Producto ocultado')}>
                  Ocultar de la tienda
                </Button>
              )}
              {product.status !== 'DRAFT' && !published && (
                <Button variant="ghost" onClick={() => void run(() => unpublish({ variables: { id: product.id, status: 'DRAFT' } }), 'Vuelto a borrador')}>
                  Volver a borrador
                </Button>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {can('catalog.manage') && (
        <Card>
          <CardHeader>
            <CardTitle className="text-base">Vista previa</CardTitle>
            <CardDescription>Genera un enlace temporal para ver el producto en la tienda aunque sea un borrador. Cualquiera con el enlace puede verlo hasta que caduque.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <NativeSelect aria-label="Duración del enlace" className="w-44" value={ttl} onChange={(e) => setTtl(e.target.value)}>
                <option value="30">Caduca en 30 min</option>
                <option value="120">Caduca en 2 horas</option>
                <option value="1440">Caduca en 24 horas</option>
              </NativeSelect>
              <Button variant="outline" disabled={issuing} onClick={() => void makeLink()}>
                Generar enlace
              </Button>
            </div>
            {link && (
              <div className="flex items-center gap-2 rounded-md bg-muted p-2 text-sm">
                <code className="min-w-0 flex-1 truncate" data-testid="preview-link">
                  {link}
                </code>
                <Button size="icon" variant="ghost" aria-label="Copiar enlace" onClick={() => void navigator.clipboard?.writeText(link).then(() => toast.success('Enlace copiado'))}>
                  <Copy />
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      )}
    </div>
  )
}
