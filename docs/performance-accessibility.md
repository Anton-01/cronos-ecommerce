# Auditoría de rendimiento y accesibilidad del storefront

**Cómo se midió** (reproducible): `next build` + `next start` contra la API real con datos de demostración; **Lighthouse** (móvil y escritorio, throttling simulado de «4G lenta», que es el modo por defecto y el más exigente) y **axe-core** (reglas WCAG 2.0/2.1/2.2 A y AA + buenas prácticas) con Playwright, en escritorio (1366 px) y móvil (390 px). Los scripts viven fuera del repositorio; basta `npm i lighthouse @axe-core/playwright` en una carpeta aparte. Medir en una máquina cargada mete ruido: una página puede dar 75 en una pasada y 98 en la siguiente (tareas largas «inatribuibles»); por eso cada cifra dudosa se repitió.

## Antes → después (inicio, móvil)

| | Rendimiento | Accesibilidad | Buenas prácticas | SEO |
|---|---|---|---|---|
| Antes | 83 | 92 | 92 | 66 (robots en desarrollo) |
| Después | 97–98 | **100** | **100** | **100** |

Páginas medidas (móvil, después): inicio 98 · categoría 98–100 · producto 98–99 · página informativa 100 · contacto/carrito se mantienen en 100 de accesibilidad y buenas prácticas. Escritorio: 83–99 según la página (LCP ≈ 2,5 s **solo por la simulación de red lenta**; en la misma máquina sin throttling carga en < 0,6 s). **axe: 0 violaciones en 12 páginas × 2 tamaños** (inicio, categoría, producto simple y variable, carrito, información, contacto, búsqueda, login, registro, rastreo, inicio en inglés).

## Qué se encontró y se corrigió

**Errores reales (no solo de puntaje)**
- **El manifiesto de la app lo bloqueaba la CSP** (el tema apuntaba al host de la API): ahora se pide al mismo origen (`/manifest.webmanifest`, reescrito a la API).
- **Las utilidades de Tailwind no pisaban `a { color: inherit }`**: CSS sin capa le gana siempre al de las capas, así que cualquier enlace con `text-on-secondary` (por ejemplo el orden activo en los listados) salía gris sobre fondo oscuro. Los estilos base y de componentes pasaron a `@layer base` / `@layer components`.
- **`experimental.inlineCss` de Next rompe la CSP**: emite `<style>` sin nonce y el navegador lo rechaza (página sin estilos). Se probó, se detectó con la prueba de violaciones y se descartó (queda anotado en `next.config.ts`).

**Accesibilidad**
- **Contraste**: el naranja de marca como *texto* (precios) daba 2,2:1. La API ahora deriva `--c-primary-ink`, el tono más cercano al color de marca que sí cumple WCAG AA (≥ 4,5) sobre el fondo y las tarjetas, para cualquier tema; el botón sigue en el naranja de marca. Los textos por defecto del tema (`#767676` sobre beige = 4,1:1) pasaron a `#666666` y `#4A4A4A`; los selectores de tienda/idioma de la barra superior y las citas de las páginas también.
- **Nombre accesible** del carrito y de las tarjetas de producto: el nombre ahora contiene el texto visible (regla `label-content-name-mismatch`).
- **Tamaño de los objetivos táctiles**: puntos del carrusel (10 → 24 px de área), enlaces del pie.
- **Jerarquía de títulos**: las tarjetas de un listado son `h2` bajo el `h1` (en la portada siguen siendo `h3` bajo el título de sección).

**Rendimiento**
- **CSS de Google Fonts en línea** (lo descarga el servidor, solo de `fonts.googleapis.com`, cacheado y refrescado con el tema): quitaba ~0,85 s de bloqueo del primer pintado en móvil.
- `preconnect` al origen de las imágenes, `browserslist` moderno (menos JavaScript heredado).
- **Caché de imágenes**: nginx sirve `/storage/` con `immutable` a un año (los archivos se nombran por ID y no cambian).
- **SEO**: meta descripción de respaldo en categorías y páginas informativas que no la tengan.

## Lo que se dejó y por qué
- **`bf-cache`** (caché de ida y vuelta): Lighthouse lo marca «no accionable»: las páginas dinámicas llevan `no-store` y las precargas RSC también. Forzar otra cabecera haría que el carrito/usuario aparecieran desactualizados al volver atrás.
- **CSS de Next (7 KB) bloqueante** (~160 ms): meterlo en línea exige nonce y Next 16 no lo pone; pasar a `'unsafe-inline'` en estilos debilitaría la CSP.
- **`is-crawlable` / meta descripción en `/cart`**: intencional (`noindex`, es transaccional). En desarrollo la API devuelve `Disallow: /` (solo producción indexa): la auditoría de SEO se hizo con `SEO_ALLOW_INDEXING=true`.
- **No medido**: el pago con Stripe (sin sandbox), el panel de administración (solo se verificó `tsc`/build/tests), lectores de pantalla reales (NVDA/VoiceOver): axe y Lighthouse detectan ~30–40 % de los problemas de accesibilidad; falta una revisión manual con teclado y lector de pantalla.

## CSP
Ver `storefront/src/lib/csp.ts`. Verificado en navegador: 0 violaciones en inicio, listado, producto, carrito, páginas, contacto (Turnstile) y boletín; un manejador en línea inyectado queda bloqueado y reportado. `CSP_REPORT_ONLY=1` la pone en modo informe y `CSP_REPORT_URI` envía los reportes; `CSP_IMG_SRC` añade orígenes de imágenes (una CDN).
