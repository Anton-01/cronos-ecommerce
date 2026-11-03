import type { Metadata, Viewport } from 'next'
import { headers } from 'next/headers'

import { Footer } from '@/components/Footer'
import { Header } from '@/components/Header'
import { MarketPrompt } from '@/components/MarketControls'
import { MobileTabBar } from '@/components/MobileTabBar'
import { getCart } from '@/lib/cart'
import { API_URL, LOCALES, SITE_URL } from '@/lib/config'
import { imgOrigins } from '@/lib/csp'
import { getMarket, previewToken, resolveLocale } from '@/lib/context'
import { getCategoryTree, getFontCss, getNewsletterConfig, getPageLinks, getTheme } from '@/lib/data'
import { messages } from '@/lib/i18n'
import { getMe } from '@/lib/user'

import '../globals.css'

type Props = { children: React.ReactNode; params: Promise<{ locale: string }> }

export async function generateMetadata({ params }: Pick<Props, 'params'>): Promise<Metadata> {
  const locale = resolveLocale((await params).locale)
  const { market } = await getMarket()
  const theme = await getTheme(locale, market.code, await previewToken())
  const favicon = theme.assets.favicon?.url
  return {
    metadataBase: new URL(SITE_URL),
    title: { default: theme.siteName, template: `%s | ${theme.siteName}` },
    description: theme.tagline || undefined,
    manifest: '/manifest.webmanifest', // same origin (the API's file is served through this site: it keeps the CSP and the install prompt simple)
    icons: favicon ? { icon: favicon, apple: favicon } : undefined,
    openGraph: { siteName: theme.siteName, locale: LOCALES[locale].og, type: 'website', images: theme.assets.og?.url ? [theme.assets.og.url] : undefined },
  }
}

export async function generateViewport({ params }: Pick<Props, 'params'>): Promise<Viewport> {
  const locale = resolveLocale((await params).locale)
  const { market } = await getMarket()
  return { themeColor: (await getTheme(locale, market.code, await previewToken())).themeColor, width: 'device-width', initialScale: 1 }
}

export default async function LocaleLayout({ children, params }: Props) {
  const locale = resolveLocale((await params).locale)
  const { market, markets, context } = await getMarket()
  const [theme, categories, cart, me, pages] = await Promise.all([getTheme(locale, market.code, await previewToken()), getCategoryTree(locale), getCart(), getMe(), getPageLinks(locale, market.code).catch(() => [])])
  const newsletter = theme.features.newsletter === false ? null : await getNewsletterConfig().catch(() => null)
  const nonce = (await headers()).get('x-nonce') ?? undefined
  const fontCss = await getFontCss(theme.fontsHref)
  const mediaOrigin = imgOrigins(API_URL).find((o) => o !== SITE_URL)
  const t = messages(locale)
  const vars = Object.entries(theme.cssVariables).filter(([k]) => k.startsWith('--')).map(([k, v]) => `${k}:${v.replace(/[;{}<>]/g, '')}`).join(';')

  return (
    <html lang={locale}>
      <head>
        {mediaOrigin && <link rel="preconnect" href={mediaOrigin} />}
        {theme.fontsHref && (
          <>
            <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
            {fontCss ? <style nonce={nonce} dangerouslySetInnerHTML={{ __html: fontCss }} /> : <link rel="stylesheet" href={theme.fontsHref} nonce={nonce} />}
          </>
        )}
        {/* The published theme, derived by the API: colours, fonts, radii and spacing for the whole shop. */}
        <style nonce={nonce} dangerouslySetInnerHTML={{ __html: `:root{${vars}}` }} />
      </head>
      <body>
        <a href="#main" className="sr-only-focusable btn btn-primary left-2 top-2 z-50">{t.skip}</a>
        <Header theme={theme} locale={locale} categories={categories} markets={markets} market={market} cart={cart} ribbon userName={me ? (me.profile?.firstName ?? me.email) : null} />
        <main id="main" className="pb-20 lg:pb-0">{children}</main>
        <Footer theme={theme} locale={locale} market={market} pages={pages} newsletter={newsletter} />
        <MobileTabBar locale={locale} cartCount={cart?.lines.length ?? 0} wishlist={theme.features.wishlist === true} />
        {context.outside && context.prompt && context.detectedMarket && context.selectedMarket && (
          <MarketPrompt prompt={context.prompt} detected={context.detectedMarket.code} selected={context.selectedMarket.code} />
        )}
      </body>
    </html>
  )
}
