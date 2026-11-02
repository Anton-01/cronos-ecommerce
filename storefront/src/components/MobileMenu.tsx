'use client'

import Link from 'next/link'
import { useEffect, useRef, useState } from 'react'

export interface MenuItem { label: string; href: string; children?: MenuItem[] }

/** Side drawer for phones: search on top, then categories and the shop's own links. Native <dialog> gives focus trapping and Esc for free. */
export function MobileMenu({ items, links, labels, searchAction }: { items: MenuItem[]; links: MenuItem[]; labels: { menu: string; close: string; search: string; searchPlaceholder: string; categories: string }; searchAction: string }) {
  const [open, setOpen] = useState(false)
  const ref = useRef<HTMLDialogElement>(null)
  useEffect(() => {
    const d = ref.current
    if (!d) return
    if (open && !d.open) d.showModal()
    if (!open && d.open) d.close()
  }, [open])

  return (
    <>
      <button type="button" className="p-2 lg:hidden" aria-label={labels.menu} aria-haspopup="dialog" onClick={() => setOpen(true)}>
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" aria-hidden><path d="M4 7h16M4 12h16M4 17h16" /></svg>
      </button>
      <dialog ref={ref} onClose={() => setOpen(false)} aria-label={labels.menu} className="m-0 h-full max-h-none w-[min(85vw,22rem)] bg-surface p-0 backdrop:bg-black/50" onClick={(e) => e.target === ref.current && setOpen(false)}>
        <div className="flex h-full flex-col gap-4 overflow-y-auto p-4">
          <div className="flex justify-end">
            <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)}>{labels.close}</button>
          </div>
          <form action={searchAction} role="search" className="flex gap-2">
            <input name="q" type="search" className="field" placeholder={labels.searchPlaceholder} aria-label={labels.search} />
            <button className="btn btn-dark" type="submit">{labels.search}</button>
          </form>
          <nav aria-label={labels.categories}>
            <p className="mb-2 text-xs font-semibold uppercase" style={{ color: 'var(--c-heading)' }}>{labels.categories}</p>
            <ul className="space-y-1">
              {items.map((c) => (
                <li key={c.href}>
                  <Link href={c.href} onClick={() => setOpen(false)} className="block py-2 font-medium" style={{ color: 'var(--c-heading)' }}>{c.label}</Link>
                  {c.children && c.children.length > 0 && (
                    <ul className="ml-3 border-l pl-3" style={{ borderColor: 'var(--c-border)' }}>
                      {c.children.map((s) => <li key={s.href}><Link href={s.href} onClick={() => setOpen(false)} className="block py-1.5 text-sm">{s.label}</Link></li>)}
                    </ul>
                  )}
                </li>
              ))}
            </ul>
          </nav>
          {links.length > 0 && (
            <ul className="space-y-1 border-t pt-3" style={{ borderColor: 'var(--c-border)' }}>
              {links.map((l) => <li key={l.href}><Link href={l.href} onClick={() => setOpen(false)} className="block py-1.5 text-sm">{l.label}</Link></li>)}
            </ul>
          )}
        </div>
      </dialog>
    </>
  )
}
