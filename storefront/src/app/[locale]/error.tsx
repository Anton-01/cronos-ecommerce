'use client'

import { DEFAULT_LOCALE } from '@/lib/config'
import { messages } from '@/lib/i18n'

export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const t = messages(DEFAULT_LOCALE)
  return (
    <div role="alert" className="container-x flex flex-col items-center gap-3 py-24 text-center">
      <p className="text-2xl">{t.error}</p>
      <button type="button" className="btn btn-primary" onClick={reset}>{t.retry}</button>
    </div>
  )
}
