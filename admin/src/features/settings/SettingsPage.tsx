import { useQuery } from '@apollo/client/react'

import { Skeleton } from '@/components/ui/skeleton'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/features/auth/AuthProvider'
import { AdminSettingsDocument } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import { GroupForm } from './GroupForm'

const GROUP_LABELS: Record<string, string> = {
  identity: 'Identidad',
  contact: 'Contacto',
  social: 'Redes sociales',
  legal: 'Legal',
  seo: 'SEO',
  notifications: 'Notificaciones',
}

export function SettingsPage() {
  const { can } = useAuth()
  const { data, error, loading } = useQuery(AdminSettingsDocument)

  const groups = [...new Set(data?.adminSettings.map((s) => s.group) ?? [])]

  return (
    <div className="max-w-3xl space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Configuración</h1>
        <p className="text-sm text-muted-foreground">Datos de la tienda que se muestran en el sitio. Los cambios se publican al instante.</p>
      </div>

      {error && (
        <p role="alert" className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
          {friendlyMessage(parseApiError(error))}
        </p>
      )}
      {loading && !data && <Skeleton className="h-64 w-full" />}

      {data && groups.length > 0 && (
        <Tabs defaultValue={groups[0]}>
          <TabsList className="flex-wrap">
            {groups.map((g) => (
              <TabsTrigger key={g} value={g}>
                {GROUP_LABELS[g] ?? g}
              </TabsTrigger>
            ))}
          </TabsList>
          {groups.map((g) => {
            const settings = data.adminSettings.filter((s) => s.group === g)
            return (
              <TabsContent key={g} value={g}>
                {/* The key re-seeds the form from the server after every save. */}
                <GroupForm key={JSON.stringify(settings)} settings={settings} locales={data.supportedLocales} canEdit={can('settings.update')} />
              </TabsContent>
            )
          })}
        </Tabs>
      )}
    </div>
  )
}
