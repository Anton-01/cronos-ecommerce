import { useMutation, useQuery } from '@apollo/client/react'
import { useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Skeleton } from '@/components/ui/skeleton'
import { useAuth } from '@/features/auth/AuthProvider'
import { AssignUserRolesDocument, RolesDocument, SetUserActiveDocument, type UserRowFragment } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

interface Props {
  user: UserRowFragment | null
  onClose: () => void
}

export function UserDialog({ user, onClose }: Props) {
  return (
    <Dialog open={user !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>{user && <UserDetails key={user.id} user={user} />}</DialogContent>
    </Dialog>
  )
}

function UserDetails({ user }: { user: UserRowFragment }) {
  const { can, user: me } = useAuth()
  const canEdit = can('users.update')
  const canAssign = can('users.assign-roles')
  const canListRoles = can('roles.view')

  const { data: rolesData } = useQuery(RolesDocument, { skip: !canAssign || !canListRoles })
  const [setActive, { loading: toggling }] = useMutation(SetUserActiveDocument)
  const [assign, { loading: saving }] = useMutation(AssignUserRolesDocument)
  const [selected, setSelected] = useState<string[]>(user.roles)
  const [confirmDisable, setConfirmDisable] = useState(false)

  const isSelf = me?.id === user.id
  const dirty = selected.length !== user.roles.length || selected.some((r) => !user.roles.includes(r))
  const name = [user.profile?.firstName, user.profile?.lastName].filter(Boolean).join(' ')

  const run = async (action: () => Promise<unknown>, success: string) => {
    try {
      await action()
      toast.success(success)
    } catch (error) {
      toast.error(friendlyMessage(parseApiError(error)))
    }
  }

  return (
    <>
      <DialogHeader>
        <DialogTitle>{name || user.email}</DialogTitle>
        <DialogDescription>{user.email}</DialogDescription>
      </DialogHeader>

      <dl className="grid grid-cols-2 gap-3 text-sm">
        <div>
          <dt className="text-muted-foreground">Estado</dt>
          <dd>
            <Badge variant={user.isActive ? 'success' : 'destructive'}>{user.isActive ? 'Activo' : 'Deshabilitado'}</Badge>
          </dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Correo verificado</dt>
          <dd>{user.emailVerifiedAt ? 'Sí' : 'No'}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Teléfono</dt>
          <dd>{user.profile?.phone ?? '—'}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Idioma</dt>
          <dd>{user.profile?.locale ?? '—'}</dd>
        </div>
      </dl>

      <fieldset className="space-y-2" disabled={!canAssign}>
        <legend className="mb-1 text-sm font-medium">Roles</legend>
        {canAssign && canListRoles && !rolesData ? (
          <Skeleton className="h-12 w-full" aria-label="Cargando roles" />
        ) : canAssign && canListRoles ? (
          <div className="grid grid-cols-2 gap-2">
            {rolesData?.roles.map((role) => (
              <div key={role.id} className="flex items-center gap-2">
                <Checkbox
                  id={`role-${role.name}`}
                  checked={selected.includes(role.name)}
                  onCheckedChange={(checked) => setSelected((prev) => (checked ? [...prev, role.name] : prev.filter((r) => r !== role.name)))}
                />
                <Label htmlFor={`role-${role.name}`}>{role.name}</Label>
              </div>
            ))}
          </div>
        ) : (
          <div className="flex flex-wrap gap-1">
            {user.roles.map((role) => (
              <Badge key={role} variant="secondary">
                {role}
              </Badge>
            ))}
          </div>
        )}
        {canAssign && canListRoles && (
          <Button
            size="sm"
            disabled={!dirty || saving}
            onClick={() => run(() => assign({ variables: { id: user.id, roles: selected } }), 'Roles actualizados')}
          >
            Guardar roles
          </Button>
        )}
      </fieldset>

      {canEdit && (
        <div className="flex items-center justify-between rounded-md border p-3">
          <div className="text-sm">
            <div className="font-medium">{user.isActive ? 'Deshabilitar cuenta' : 'Reactivar cuenta'}</div>
            <div className="text-muted-foreground">
              {isSelf ? 'No puedes deshabilitar tu propia cuenta.' : user.isActive ? 'Se cerrarán todas sus sesiones.' : 'Podrá iniciar sesión de nuevo.'}
            </div>
          </div>
          {user.isActive && !confirmDisable ? (
            <Button variant="outline" size="sm" disabled={isSelf} onClick={() => setConfirmDisable(true)}>
              Deshabilitar
            </Button>
          ) : (
            <Button
              variant={user.isActive ? 'destructive' : 'default'}
              size="sm"
              disabled={toggling || isSelf}
              onClick={() =>
                run(async () => {
                  await setActive({ variables: { id: user.id, active: !user.isActive } })
                  setConfirmDisable(false)
                }, user.isActive ? 'Cuenta deshabilitada' : 'Cuenta reactivada')
              }
            >
              {user.isActive ? 'Confirmar' : 'Reactivar'}
            </Button>
          )}
        </div>
      )}
    </>
  )
}
