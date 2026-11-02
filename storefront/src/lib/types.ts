export interface Money { amount: string; currency: string }
export interface Pricing {
  market: string
  currency: string
  taxIncluded: boolean
  price: Money
  listPrice: Money
  compareAt: Money | null
  onSale: boolean
  priceWithTax: Money
  priceWithoutTax: Money
  taxAmount: Money
  rollPrice: Money | null
  rollPriceWithTax: Money | null
}
export interface Sales {
  unit: 'PIECE' | 'METER' | string
  step: string
  min: string
  max: string | null
  sellByCut: boolean
  sellByRoll: boolean
  rollLength: string | null
  rollPrice: string | null
}
export interface Image { id: string; url: string; thumbUrl: string | null; srcset: string | null; alt: string | null; width: number | null; height: number | null }
export type Stock = 'IN_STOCK' | 'ON_BACKORDER' | 'OUT_OF_STOCK'
export interface ProductSummary {
  id: string
  type: string
  slug: string | null
  name: string | null
  shortDescription: string | null
  sales: Sales
  image: Image | null
  stockStatusIn: Stock
  pricing: Pricing | null
}
export interface CategoryNode { id: string; slug: string | null; name: string | null; depth: number; image?: Image | null; children: CategoryNode[] }
export interface CartLine { productId: string; sku: string | null; name: string; byRoll: boolean; quantity: string; unitPrice: string; lineTotal: string; discount: string; tax: string; total: string }
export interface Totals { currency: string; taxIncluded: boolean; subtotal: string; itemsDiscount: string; shipping: string; shippingDiscount: string; tax: string; total: string }
export interface Cart { id: string; market: string; region: string | null; couponCode: string | null; couponError: string | null; lines: CartLine[]; totals: Totals | null; warning: string | null }
export interface Market {
  code: string
  name: string
  countryCode: string
  defaultLocale: string
  pricesIncludeTax: boolean
  currency: { code: string; symbol: string; decimals: number }
  profile: { tradeName: string | null; contactEmail: string | null; contactPhone: string | null; supportHours: string | null; countries: string[] }
}
export interface NavLink { label: string; url: string }
export interface Theme {
  version: number
  siteName: string
  tagline: string
  themeColor: string
  tokens: { layout: { containerWidth: number; showTopbar: boolean; showCategoryNav: boolean; stickyHeader: boolean; headerStyle: string; gridColumnsDesktop: number; gridColumnsMobile: number; backgroundTexture: string }; shape: { categoryShape: string; buttonStyle: string } }
  cssVariables: Record<string, string>
  fontsHref: string | null
  assets: Record<string, { kind: string; url: string | null; width: number | null; height: number | null; srcset: string | null; alt?: string | null } | null>
  navigation: { topbarLinks: NavLink[]; footerColumns: { title: string; links: NavLink[] }[]; social: Record<string, string>; announcements: { type: 'text' | 'free_shipping'; text: string; url: string | null }[]; footerNote: string }
  home: HomeSection[]
  features: Record<string, boolean>
  manifestUrl: string
}
export interface HomeSection { id: string; type: string; enabled: boolean; settings: Record<string, any> }
