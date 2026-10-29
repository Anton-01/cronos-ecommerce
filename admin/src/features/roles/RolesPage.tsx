import { useQuery } from '@apollo/client/react'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { RolesDocument } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

export function RolesPage() {
  const { data, error, loading } = useQuery(RolesDocument)

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Roles</h1>
        <p className="text-sm text-muted-foreground">Cada rol agrupa permisos con el formato recurso.acción.</p>
      </div>
      {error && <p role="alert" className="text-sm text-destructive">{friendlyMessage(parseApiError(error))}</p>}
      {loading && !data && <Skeleton className="h-40 w-full" />}
      <div className="grid gap-4 lg:grid-cols-2">
        {data?.roles.map((role) => (
          <Card key={role.id}>
            <CardHeader>
              <CardTitle className="text-base">{role.name}</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-wrap gap-2">
              {role.permissions.length === 0 && <span className="text-sm text-muted-foreground">Sin permisos de panel.</span>}
              {role.permissions.map((permission) => (
                <Badge key={permission} variant="outline">
                  {permission}
                </Badge>
              ))}
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
