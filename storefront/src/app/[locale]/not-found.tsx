import Link from 'next/link'

import { DEFAULT_LOCALE, href } from '@/lib/config'
import { messages } from '@/lib/i18n'

export default function NotFound() {
  const t = messages(DEFAULT_LOCALE)
  return (
    <div className="container-x flex flex-col items-center gap-3 py-24 text-center">
      <h1 className="text-4xl">404</h1>
      <p className="text-xl">{t.notFound}</p>
      <p>{t.notFoundText}</p>
      <Link href={href.home(DEFAULT_LOCALE)} className="btn btn-primary mt-2">{t.returnToShop}</Link>
    </div>
  )
}
