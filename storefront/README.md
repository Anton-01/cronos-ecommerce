# Cronos Storefront

Next.js 16 (App Router, SSR) + Tailwind 4. Everything visual (logo, colours, fonts, menus, home blocks) comes from the API's published theme (`storefrontTheme`), so the shop is re-skinned from the back office with no deploy. Design notes: `../docs/storefront-design.md`.

```bash
cp .env.example .env.local   # API_URL, NEXT_PUBLIC_SITE_URL
npm install
npm run dev                  # http://localhost:3000
npm run typecheck && npm run lint && npm test && npm run build
```

- URLs follow the API's `seoRoutes`: `/{es-mx|en-us}/{productos|products}/{slug}`, `/…/{categorias|categories}/{slug}`. `/` redirects to the visitor's store and language (country decided by the API from the IP).
- The browser only talks to this site: `/api/graphql` forwards to the API, adds the sign-in token (httpOnly cookie) and the real client IP.
- `/robots.txt`, `/sitemap.xml`, `/manifest.webmanifest`, `/favicon.ico` are rewritten to the API.
- Sample data for development: `php artisan db:seed --class=DemoCatalogSeeder` (fresh database).
