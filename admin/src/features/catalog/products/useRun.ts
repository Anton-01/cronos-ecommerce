import { useCallback } from 'react'
import { toast } from 'sonner'

import { friendlyMessage, parseApiError } from '@/lib/errors'

/** Runs a mutation, shows a success toast, or the API's own explanation when it is refused. Resolves to true on success. */
export function useRun() {
  return useCallback(async (action: () => Promise<unknown>, success: string): Promise<boolean> => {
    try {
      await action()
      toast.success(success)
      return true
    } catch (error) {
      const parsed = parseApiError(error)
      toast.error(parsed.code === 'CANNOT_PUBLISH' ? `No se puede publicar: ${parsed.message.replace(/^The product cannot be published: /, '')}` : friendlyMessage(parsed))
      return false
    }
  }, [])
}
