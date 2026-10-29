import { useApolloClient } from '@apollo/client/react'
import { useCallback, useState } from 'react'
import { toast } from 'sonner'

import { UploadMediaDocument } from '@/graphql/generated'
import { UploadError, uploadFile } from '@/lib/upload'

/** Uploads files one after another (kind to small servers) and refreshes the library afterwards. */
export function useMediaUpload(collection = 'library') {
  const client = useApolloClient()
  const [uploading, setUploading] = useState(0)

  const upload = useCallback(
    async (files: FileList | File[]) => {
      const list = Array.from(files)
      setUploading((n) => n + list.length)

      for (const file of list) {
        try {
          await uploadFile(UploadMediaDocument, { file, collection })
          toast.success(`${file.name} subido`)
        } catch (error) {
          toast.error(`${file.name}: ${error instanceof UploadError ? error.message : 'no se pudo subir'}`)
        } finally {
          setUploading((n) => n - 1)
        }
      }

      await client.refetchQueries({ include: ['MediaLibrary'] })
    },
    [client, collection],
  )

  return { upload, uploading }
}
