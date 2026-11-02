import { useEffect } from 'react'

import type { Navigation, Tokens } from './types'

/** A small mock of the storefront painted with the SAME css variables the server derives, so what is seen here is what will be published. */
export function Preview({ tokens, navigation, vars, fontsHref, logoUrl }: { tokens: Tokens; navigation: Navigation; vars: Record<string, string>; fontsHref: string | null; logoUrl: string | null }) {
  useEffect(() => {
    if (!fontsHref) return
    const link = document.createElement('link')
    link.rel = 'stylesheet'
    link.href = fontsHref
    document.head.appendChild(link)
    return () => link.remove()
  }, [fontsHref])

  const style = Object.fromEntries(Object.entries(vars).filter(([k]) => k !== '--bg-texture')) as React.CSSProperties
  const name = tokens.identity.siteName || 'Mi tienda'
  const card = { background: 'var(--c-surface)', borderRadius: 'var(--radius-card)', border: 'var(--card-border)', boxShadow: 'var(--card-shadow)' }

  return (
    <div data-testid="theme-preview" className="overflow-hidden rounded-lg border text-[13px]" style={{ ...style, background: 'var(--c-bg)', color: 'var(--c-text)', fontFamily: 'var(--font-body)', lineHeight: 1.5 }}>
      {tokens.layout.showTopbar && <div className="px-3 py-1 text-[11px]" style={{ background: 'var(--c-topbar-bg)', color: 'var(--c-topbar-text)' }}>{navigation.topbarLinks.map((l) => l.label['es-MX']).filter(Boolean).join(' · ') || 'Barra superior'}</div>}
      <div className="flex items-center justify-between gap-2 px-3 py-2" style={{ background: 'var(--c-header-bg)', color: 'var(--c-header-text)' }}>
        {logoUrl ? <img src={logoUrl} alt={name} className="h-7 w-auto max-w-[120px] object-contain" /> : <strong style={{ fontFamily: 'var(--font-heading)' }}>{name}</strong>}
        <span className="rounded-full px-3 py-1 text-[11px]" style={{ background: 'var(--c-secondary)', color: 'var(--c-on-secondary)', borderRadius: 'var(--radius-button)' }}>Carrito</span>
      </div>
      {tokens.layout.showCategoryNav && <div className="flex items-center justify-between px-3 py-1 text-[11px]" style={{ background: 'var(--c-header-bg)', color: 'var(--c-header-text)' }}><span><b style={{ color: 'var(--c-primary)' }}>Listones</b> · Moños · Telas</span><span className="rounded px-2" style={{ background: 'var(--c-ribbon-bg)', color: 'var(--c-ribbon-text)' }}>Envío gratis</span></div>}
      <div className="space-y-3 p-3">
        <h3 style={{ fontFamily: 'var(--font-heading)', fontWeight: 'var(--fw-heading)' as never, color: 'var(--c-heading)', textTransform: 'var(--heading-transform)' as never, fontSize: 18 }}>Novedades</h3>
        <div className="grid grid-cols-2 gap-2">
          {[1, 2].map((n) => (
            <div key={n} className="p-2" style={card}>
              <div className="relative mb-2 aspect-square" style={{ background: 'var(--c-surface-alt)', borderRadius: 'var(--radius-card)' }}>
                <span className="absolute left-1 top-1 px-1.5 text-[10px]" style={{ background: 'var(--c-sale)', color: 'var(--c-on-sale)', borderRadius: 'var(--radius-badge)' }}>-15%</span>
              </div>
              <div style={{ color: 'var(--c-heading)', fontFamily: 'var(--font-heading)', fontWeight: 600 }}>Listón satinado</div>
              <div style={{ color: 'var(--c-primary)', fontWeight: 600 }}>$ 24.00 / m</div>
            </div>
          ))}
        </div>
        <div className="flex gap-2">
          <button type="button" tabIndex={-1} className="px-4 py-1.5" style={{ background: tokens.shape.buttonStyle === 'outline' ? 'transparent' : 'var(--c-primary)', color: tokens.shape.buttonStyle === 'outline' ? 'var(--c-primary)' : 'var(--c-on-primary)', border: '1px solid var(--c-primary)', borderRadius: 'var(--radius-button)' }}>Agregar al carrito</button>
          <button type="button" tabIndex={-1} className="px-4 py-1.5" style={{ background: 'var(--c-secondary)', color: 'var(--c-on-secondary)', borderRadius: 'var(--radius-button)' }}>Comprar ya</button>
        </div>
        <input readOnly tabIndex={-1} placeholder="Buscar…" className="w-full border px-3 py-1.5" style={{ borderRadius: 'var(--radius-input)', background: 'var(--c-surface)', borderColor: 'var(--c-border)' }} />
      </div>
      <div className="px-3 py-3 text-[11px]" style={{ background: 'var(--c-footer-bg)', color: 'var(--c-footer-text)' }}><b style={{ color: 'var(--c-footer-heading)' }}>{name}</b> · {navigation.footerNote['es-MX'] || 'Pie de página'}</div>
    </div>
  )
}
