import { useMutation, useQuery } from '@apollo/client/react'
import { ArrowLeft } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { AdminProductDocument, DeleteProductDocument } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { useSupportedLocales } from '../shared'
import { GalleryTab } from './GalleryTab'
import { GeneralTab } from './GeneralTab'
import { GroupTab } from './GroupTab'
import { OrganizationTab } from './OrganizationTab'
import { STATUS_LABEL, TYPE_LABEL } from './ProductsPage'
import { PublishTab } from './PublishTab'
import { SalesTab } from './SalesTab'
import { VariationsTab } from './VariationsTab'
import { displayName } from './tabs'
import { useRun } from './useRun'

export function ProductEditor() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { can } = useAuth()
  const run = useRun()
  const { defaultLocale } = useSupportedLocales()
  const { data, loading, error } = useQuery(AdminProductDocument, { variables: { id } })
  const [remove] = useMutation(DeleteProductDocument)
  const product = data?.adminProduct
  const canEdit = can('catalog.manage')

  if (loading && !data) return <Skeleton className="h-64 w-full" />
  if (error) return <p role="alert" className="text-sm text-destructive">{friendlyMessage(parseApiError(error))}</p>
  if (!product) return <p className="text-sm text-muted-foreground">El producto no existe o fue eliminado.</p>

  const grouped = product.type === 'GROUPED'
  const variable = product.type === 'VARIABLE'
  // Re-seed every form from the server after a save: the key changes with the saved data.
  const seed = `${product.id}:${JSON.stringify([product.translations, product.sku, product.price, product.sales, product.categoryIds, product.tagIds, product.groupItems, product.weight, product.dimensions, product.manageStock, product.allowBackorder, product.isCustomizable, product.lowStockThreshold])}`

  return (
    <div className="space-y-4">
      <Link to="/catalog/products" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
        <ArrowLeft className="size-4" /> Productos
      </Link>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-semibold tracking-tight">{displayName(product, defaultLocale)}</h1>
        <Badge variant="outline">{TYPE_LABEL[product.type]}</Badge>
        <Badge variant={product.status === 'PUBLISHED' ? 'success' : 'secondary'}>{STATUS_LABEL[product.status]}</Badge>
        {canEdit && (
          <Button
            variant="ghost"
            size="sm"
            className="ml-auto"
            onClick={() => {
              if (!window.confirm('¿Eliminar este producto y sus variaciones?')) return
              void run(() => remove({ variables: { id: product.id } }), 'Producto eliminado').then((ok) => ok && navigate('/catalog/products'))
            }}
          >
            Eliminar
          </Button>
        )}
      </div>

      <Tabs defaultValue="general">
        <TabsList className="h-auto flex-wrap">
          <TabsTrigger value="general">General</TabsTrigger>
          {!grouped && <TabsTrigger value="sales">Venta</TabsTrigger>}
          <TabsTrigger value="organization">Organización</TabsTrigger>
          {variable && <TabsTrigger value="variations">Variaciones</TabsTrigger>}
          {grouped && <TabsTrigger value="group">Kit</TabsTrigger>}
          <TabsTrigger value="gallery">Galería</TabsTrigger>
          <TabsTrigger value="publish">
            Publicación{product.publishProblems.length > 0 && product.status !== 'PUBLISHED' ? ` (${product.publishProblems.length})` : ''}
          </TabsTrigger>
        </TabsList>
        <TabsContent value="general">
          <GeneralTab key={seed} product={product} canEdit={canEdit} />
        </TabsContent>
        {!grouped && (
          <TabsContent value="sales">
            <SalesTab key={seed} product={product} canEdit={canEdit} />
          </TabsContent>
        )}
        <TabsContent value="organization">
          <OrganizationTab key={seed} product={product} canEdit={canEdit} />
        </TabsContent>
        {variable && (
          <TabsContent value="variations">
            <VariationsTab key={`${seed}:${product.variations.length}:${product.offeredValueIds.join()}`} product={product} canEdit={canEdit} />
          </TabsContent>
        )}
        {grouped && (
          <TabsContent value="group">
            <GroupTab key={seed} product={product} canEdit={canEdit} />
          </TabsContent>
        )}
        <TabsContent value="gallery">
          <GalleryTab product={product} canEdit={canEdit} />
        </TabsContent>
        <TabsContent value="publish">
          <PublishTab product={product} />
        </TabsContent>
      </Tabs>
    </div>
  )
}
