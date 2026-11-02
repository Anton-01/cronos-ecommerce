/** The shapes the server documents for a theme. The API sends them as JSON, so they are written down once here. */
export type Localized = Record<string, string>

export interface Tokens {
  colors: Record<string, string | null>
  typography: { headingFont: string; bodyFont: string; headingWeight: number; bodyWeight: number; baseSize: number; lineHeight: number; headingTransform: 'none' | 'capitalize' | 'uppercase'; letterSpacing: number }
  shape: { radiusCard: number; radiusButton: number; radiusInput: number; radiusBadge: number; categoryShape: 'circle' | 'rounded' | 'square'; cardStyle: 'flat' | 'bordered' | 'shadow'; buttonStyle: 'filled' | 'outline' }
  layout: { containerWidth: number; density: 'comfortable' | 'compact'; gridColumnsDesktop: number; gridColumnsMobile: number; headerStyle: 'classic' | 'centered'; showTopbar: boolean; showCategoryNav: boolean; stickyHeader: boolean; backgroundTexture: 'grain' | 'none' }
  identity: { siteName: string; tagline: Localized; themeColor: string | null }
}

export interface NavLink {
  label: Localized
  url: string
}

export interface Navigation {
  topbarLinks: NavLink[]
  footerColumns: { title: Localized; links: NavLink[] }[]
  social: Record<string, string>
  announcements: { markets: string[]; text: Localized; url: string | null }[]
  showFreeShippingRibbon: boolean
  footerNote: Localized
}

export interface HeroSlide {
  image: string | null
  imageMobile: string | null
  kicker: Localized
  title: Localized
  subtitle: Localized
  priceText: Localized
  ctaLabel: Localized
  ctaUrl: string | null
  align: 'left' | 'center' | 'right'
  overlay: number
}

export interface HomeSection {
  id: string
  type: string
  enabled: boolean
  reserved?: boolean
  settings: Record<string, unknown>
}

export interface AssetFile {
  url: string
  width: number | null
  height: number | null
  format: string
  density: number
  size?: number
}

export interface Asset {
  kind: 'raster' | 'svg'
  hash: string
  url: string | null
  width: number | null
  height: number | null
  srcset: string | null
  files: AssetFile[]
}

export const LOCALES = [
  { code: 'es-MX', label: 'Español (MX)' },
  { code: 'en-US', label: 'English (US)' },
] as const

export const COLOR_GROUPS: { title: string; keys: { key: string; label: string; optional?: boolean }[] }[] = [
  {
    title: 'Marca',
    keys: [
      { key: 'primary', label: 'Color principal (precios, botón principal)' },
      { key: 'onPrimary', label: 'Texto sobre el principal (vacío = automático)', optional: true },
      { key: 'secondary', label: 'Color secundario (botón oscuro, carrito)' },
      { key: 'onSecondary', label: 'Texto sobre el secundario (vacío = automático)', optional: true },
      { key: 'sale', label: 'Descuentos' },
    ],
  },
  {
    title: 'Página',
    keys: [
      { key: 'background', label: 'Fondo de la página' },
      { key: 'surface', label: 'Tarjetas y paneles' },
      { key: 'text', label: 'Texto' },
      { key: 'heading', label: 'Títulos' },
      { key: 'link', label: 'Enlaces' },
      { key: 'border', label: 'Líneas y bordes' },
    ],
  },
  {
    title: 'Encabezado y avisos',
    keys: [
      { key: 'headerBg', label: 'Fondo del encabezado' },
      { key: 'headerText', label: 'Texto del encabezado' },
      { key: 'topbarBg', label: 'Fondo de la barra superior' },
      { key: 'topbarText', label: 'Texto de la barra superior' },
      { key: 'ribbonBg', label: 'Fondo de la cinta de avisos' },
      { key: 'ribbonText', label: 'Texto de la cinta de avisos' },
    ],
  },
  {
    title: 'Estados',
    keys: [
      { key: 'success', label: 'Éxito y etiqueta «Nuevo»' },
      { key: 'danger', label: 'Errores y etiqueta «Hot»' },
    ],
  },
  {
    title: 'Pie de página',
    keys: [
      { key: 'footerBg', label: 'Fondo' },
      { key: 'footerText', label: 'Texto' },
      { key: 'footerHeading', label: 'Títulos' },
    ],
  },
]

export const SLOTS = [
  { slot: 'LOGO', key: 'logo', imageSlot: 'logo', title: 'Logotipo', help: 'Se muestra en el encabezado.', accepts: 'image/png,image/jpeg,image/webp,image/svg+xml,.svg' },
  { slot: 'LOGO_DARK', key: 'logoDark', imageSlot: 'logo', title: 'Logotipo para fondo oscuro', help: 'Versión clara del logo para el pie de página (opcional).', accepts: 'image/png,image/jpeg,image/webp,image/svg+xml,.svg' },
  { slot: 'LOGO_MOBILE', key: 'logoMobile', imageSlot: 'logo', title: 'Logotipo para móvil', help: 'Versión compacta o solo el símbolo (opcional).', accepts: 'image/png,image/jpeg,image/webp,image/svg+xml,.svg' },
  { slot: 'FAVICON', key: 'favicon', imageSlot: 'favicon', title: 'Favicon / ícono de la app', help: 'Cuadrado. Genera todos los tamaños y el .ico.', accepts: 'image/png,image/jpeg,image/webp' },
  { slot: 'OG', key: 'og', imageSlot: 'og', title: 'Imagen para compartir en redes', help: 'Lo que se ve al compartir un enlace de la tienda.', accepts: 'image/png,image/jpeg,image/webp' },
] as const
