import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'

export function DashboardPage() {
  const { user } = useAuth()

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight">Resumen</h1>
        <p className="text-sm text-muted-foreground">Bienvenido, {user?.profile?.firstName ?? user?.email}.</p>
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Tus roles</CardTitle>
            <CardDescription>Definen lo que puedes hacer en el panel.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {user?.roles.map((role) => (
              <Badge key={role} variant="secondary">
                {role}
              </Badge>
            ))}
          </CardContent>
        </Card>
        <Card>
          <CardHeader>
            <CardTitle>Permisos efectivos</CardTitle>
            <CardDescription>{user?.permissions.length ?? 0} permisos activos.</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-wrap gap-2">
            {user?.permissions.map((permission) => (
              <Badge key={permission} variant="outline">
                {permission}
              </Badge>
            ))}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
