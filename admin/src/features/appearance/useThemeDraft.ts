import { useMutation, useQuery } from '@apollo/client/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { toast } from 'sonner'

import { AdminThemeDocument, SaveThemeDraftDocument, type AdminThemeQuery } from '@/graphql/generated'
import { friendlyMessage, parseApiError } from '@/lib/errors'

import type { Navigation, HomeSection, Tokens } from './types'

export type ThemeState = AdminThemeQuery['adminTheme']
export interface DraftValues {
  tokens: Tokens
  navigation: Navigation
  home: HomeSection[]
  features: Record<string, boolean>
}
export type SaveState = 'idle' | 'saving' | 'saved' | 'error'

const DEBOUNCE_MS = 800

/**
 * Holds the draft being edited. Edits are applied locally at once (the preview reacts immediately) and saved after a short pause;
 * the server answers with the derived CSS variables and the contrast advice, which are what the preview and the colour tab show.
 */
export function useThemeDraft() {
  const { data, loading, refetch } = useQuery(AdminThemeDocument, { fetchPolicy: 'cache-and-network' })
  const [save] = useMutation(SaveThemeDraftDocument)
  const [edited, setValues] = useState<DraftValues | null>(null)
  const [state, setState] = useState<SaveState>('idle')
  const [errors, setErrors] = useState<string | null>(null)
  const [derived, setDerived] = useState<{ cssVariables: Record<string, string>; fontsHref: string | null; contrast: { pair: string; ratio: number; needed: number; ok: boolean }[] } | null>(null)
  const valuesRef = useRef<DraftValues | null>(null)
  const pending = useRef<Partial<DraftValues>>({})
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const draft = data?.adminTheme.draft
  // What is shown is the person's own copy once they edit (or adopt a server state); until then, the server's draft.
  const fromServer = useMemo<DraftValues | null>(
    () => (draft ? { tokens: draft.tokens as Tokens, navigation: draft.navigation as Navigation, home: draft.home as HomeSection[], features: draft.features as Record<string, boolean> } : null),
    [draft],
  )
  const values = edited ?? fromServer
  useEffect(() => {
    valuesRef.current = values
  })

  const flush = useCallback(async () => {
    const patch = pending.current
    pending.current = {}
    if (Object.keys(patch).length === 0) return
    setState('saving')
    try {
      const { data: saved } = await save({ variables: patch as never })
      const next = saved?.saveThemeDraft
      if (next) setDerived({ cssVariables: next.cssVariables as Record<string, string>, fontsHref: next.fontsHref ?? null, contrast: next.contrast })
      setErrors(null)
      setState('saved')
      void refetch()
    } catch (error) {
      const parsed = parseApiError(error)
      setErrors(friendlyMessage(parsed))
      setState('error')
    }
  }, [save, refetch])

  const update = useCallback(
    (patch: Partial<DraftValues>) => {
      setValues((current) => { const base = current ?? valuesRef.current; return base ? { ...base, ...patch } : current })
      pending.current = { ...pending.current, ...patch }
      if (timer.current) clearTimeout(timer.current)
      timer.current = setTimeout(() => void flush(), DEBOUNCE_MS)
    },
    [flush],
  )

  /** Saves right now (before publishing or previewing). */
  const saveNow = useCallback(async () => {
    if (timer.current) clearTimeout(timer.current)
    await flush()
  }, [flush])

  /** Replaces the local copy with what the server holds (after a preset, a restore or a reset). */
  const adopt = useCallback((theme: { tokens: unknown; navigation: unknown; home: unknown; features: unknown; cssVariables: unknown; fontsHref?: string | null; contrast: { pair: string; ratio: number; needed: number; ok: boolean }[] }) => {
    pending.current = {}
    if (timer.current) clearTimeout(timer.current)
    setValues({ tokens: theme.tokens as Tokens, navigation: theme.navigation as Navigation, home: theme.home as HomeSection[], features: theme.features as Record<string, boolean> })
    setDerived({ cssVariables: theme.cssVariables as Record<string, string>, fontsHref: theme.fontsHref ?? null, contrast: theme.contrast })
  }, [])

  useEffect(
    () => () => {
      if (timer.current) {
        clearTimeout(timer.current)
        void flush().catch(() => toast.error('No se pudo guardar el último cambio.'))
      }
    },
    [flush],
  )

  const theme = data?.adminTheme
  return {
    theme,
    loading: loading && !theme,
    values,
    update,
    saveNow,
    adopt,
    refetch,
    state,
    error: errors,
    cssVariables: derived?.cssVariables ?? (draft?.cssVariables as Record<string, string> | undefined) ?? {},
    fontsHref: derived ? derived.fontsHref : (draft?.fontsHref ?? null),
    contrast: derived?.contrast ?? draft?.contrast ?? [],
  }
}
