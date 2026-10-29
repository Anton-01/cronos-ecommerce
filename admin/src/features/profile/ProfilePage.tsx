import { useApolloClient } from '@apollo/client/react'
import { useRef, useState } from 'react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useAuth } from '@/features/auth/AuthProvider'
import { UpdateMyAvatarDocument } from '@/graphql/generated'
import { UploadError, uploadFile } from '@/lib/upload'

import { AvatarCropper } from './AvatarCropper'

const MAX_BYTES = 5 * 1024 * 1024

export function ProfilePage() {
  const { user } = useAuth()
  const client = useApolloClient()
  const input = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const initials = (user?.profile?.firstName?.[0] ?? user?.email[0] ?? '?').toUpperCase()

  const choose = (picked: File | undefined) => {
    if (!picked) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(picked.type)) return void toast.error('Usa una imagen JPG, PNG o WebP.')
    if (picked.size > MAX_BYTES) return void toast.error('La imagen no debe superar 5 MB.')
    setFile(picked)
  }

  const upload = async (blob: Blob) => {
    try {
      await uploadFile(UpdateMyAvatarDocument, { file: new File([blob], 'avatar.jpg', { type: 'image/jpeg' }) })
      await client.refetchQueries({ include: ['AdminMe'] })
      toast.success('Foto actualizada')
      setFile(null)
    } catch (error) {
      toast.error(error instanceof UploadError ? error.message : 'No se pudo subir la foto.')
    }
  }

  return (
    <div className="max-w-xl space-y-6">
      <h1 className="text-2xl font-semibold tracking-tight">Mi perfil</h1>
      <Card>
        <CardHeader>
          <CardTitle>Foto</CardTitle>
          <CardDescription>Se muestra en el panel. JPG, PNG o WebP, hasta 5 MB.</CardDescription>
        </CardHeader>
        <CardContent className="flex items-center gap-4">
          {user?.avatarUrl ? (
            <img src={user.avatarUrl} alt="Tu foto de perfil" className="size-20 rounded-full object-cover" />
          ) : (
            <div aria-hidden className="flex size-20 items-center justify-center rounded-full bg-muted text-2xl font-semibold text-muted-foreground">
              {initials}
            </div>
          )}
          <input ref={input} type="file" hidden accept="image/jpeg,image/png,image/webp" aria-label="Elegir foto" onChange={(e) => { choose(e.target.files?.[0]); e.target.value = '' }} />
          <Button variant="outline" onClick={() => input.current?.click()}>
            Cambiar foto
          </Button>
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Cuenta</CardTitle>
          <CardDescription>{user?.email}</CardDescription>
        </CardHeader>
        <CardContent className="flex flex-wrap gap-2">
          {user?.roles.map((role) => (
            <Badge key={role} variant="secondary">
              {role}
            </Badge>
          ))}
        </CardContent>
      </Card>
      <AvatarCropper file={file} onCancel={() => setFile(null)} onDone={upload} />
    </div>
  )
}
