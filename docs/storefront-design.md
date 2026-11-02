# Storefront — especificación de diseño (Fase 9, paso 1)

Fuente: observación del demo https://woodmart.xtemos.com/furniture2/ con el navegador del entorno (2026-11-02), escritorio 1440 px y móvil 390 px.
Capturas y HTML de referencia **no se versionan** (son material de terceros): están en el scratchpad de la sesión. Aquí solo queda lo medido.

**Alcance legal/técnico.** WoodMart es un tema comercial. Replicamos la *apariencia y estructura* (layout, jerarquía, proporciones, comportamiento) con componentes propios (React + Tailwind) y **datos de nuestro catálogo**. No se copian su código, sus textos, sus imágenes, su logotipo ni sus fuentes de iconos (`woodmart-font`). Fuentes de texto: Urbanist y Work Sans son de Google Fonts (licencia OFL, se pueden usar).

## 1. Tokens

| Token | Valor medido |
|---|---|
| Fuente de títulos | **Urbanist** 700 (nav y elementos de cabecera 600) |
| Fuente de texto | **Work Sans** 400, 16 px, interlineado 1.6 |
| Color de texto | `rgb(118,118,118)` (`#767676`); títulos `#242424`; enlaces `#333` |
| Color primario (acento) | `rgb(245,154,87)` → **`#F59A57`** (precios, botón principal, subrayados, activo) |
| Alternativo (cinta de aviso) | `rgb(224,234,240)` → `#E0EAF0` |
| Fondo de página | **`#F3F2EE`** con textura sutil de grano (ruido muy fino); cabecera `#FBFAF7`; barra superior `rgba(16,16,16,.05)` sobre el fondo |
| Tarjetas / paneles | blanco `#FFF`, radio **10 px**, sin borde, sombra casi nula |
| Pie de página | negro `#000`, texto `rgba(255,255,255,.8)`, títulos blancos |
| Grises | `#f7f7f7 #f1f1f1 #bbb #a5a5a5 #767676 #666 #555 #333 #242424` |
| Radios | tarjetas 10 px · campos de formulario 30 px (píldora) · botones 30 px · etiquetas 12 px · categorías circulares 50 % |
| Contenedor | ancho máx. **1400 px**, relleno lateral 15 px |
| Campo de formulario | alto 42 px, borde 1 px `rgba(0,0,0,.1)`, fondo blanco, fuente 14 px |
| Etiquetas de producto | "NEW" verde `#438E44`, descuento "-15 %" naranja `#F59A57`, "HOT" rojo; blanco, 12 px, radio 12 px, alto 24 px |
| Botón primario | fondo `#F59A57`, texto blanco 13 px/600, radio 30 px, alto ~42 px; secundario negro `#242424` ("Buy now"); terciario gris `#F3F3F3` texto `#3E3E3E` |
| Botón carrito (cabecera) | píldora negra, texto blanco, monto + icono, contador naranja |

## 2. Estructura global

**Cabecera (escritorio)** — 3 franjas: barra superior 40 px (moneda/país a la izquierda, enlaces "Gift cards · Showrooms · About us", teléfono y "Contact with an expert" a la derecha) → fila principal 64 px (logo, **buscador píldora** ancho con resultados en panel de 2 columnas con miniatura+nombre+precio, iconos comparar / favoritos con contador, "Login / Register" en píldora gris, **carrito negro**) → fila de categorías 45 px (icono + nombre, activo en naranja) con una **cinta azul pálido** "Envío gratis desde…" alineada a la derecha.
**Cabecera (móvil)** — hamburguesa · logo centrado · carrito. **Barra inferior fija** de 4 pestañas: Tienda, Favoritos (o Filtros en listados), Carrito, Mi cuenta. El menú es un cajón lateral con buscador arriba y pestañas "Categorías / Menú".
**Pie** — negro: logo + redes; columnas "Enlaces útiles", "Categorías" (2 columnas), bloque de app; línea inferior con copyright y logos de medios de pago.
**Banner de título** (listados, carrito, cuenta): imagen de fondo a ancho completo de ~235–330 px con título gigante (≈78 px, blanco) y breadcrumb o pasos.

## 3. Páginas

### Inicio
1. **Hero** carrusel a ancho completo (≈600 px) con fondo de color suave, titular grande, precio y botón blanco "Shop now"; flechas circulares blancas y puntos de paginación en píldora.
2. **Nuestras categorías**: rejilla 5×2 de **círculos** con foto y etiqueta blanca en píldora al centro.
3. **Más vendidos de la semana**: título + pestañas de filtro por categoría (la activa subrayada en naranja); tarjetas de producto (ver §4) en 5 columnas.
4. **Compra por marcas**: 5 tarjetas con foto, logo circular y país.
5. **Colecciones**: mosaico tipo "masonry" de fotos y tarjetas de color con texto.
6. Bloque editorial con imagen recortada en forma orgánica + lista con viñetas naranjas + video en píldora.
7. **Últimos artículos** (blog): 4 tarjetas con foto, autor, categoría/fecha, título y extracto; botón "Visit the blog".

### Listado de categoría
Banner con título y flecha de regreso · **barra lateral** (tarjeta blanca) con filtros: precio (slider naranja + "Filter"), marca (con buscador y conteos), color (círculos con conteo), etc. · barra de herramientas: "Showing 1–12 of 16 results", "Show: 9 / **12** / 18 / 24", iconos de vista (lista / 3 / 4 columnas), orden (select píldora) · rejilla de **3 columnas** con sidebar (4–5 sin). Móvil: 2 columnas, "Show sidebar" abre un cajón de filtros (con "Close"), ordenar en icono.

### Ficha de producto
Breadcrumb + flechas anterior/siguiente · galería a la izquierda (rejilla 2×2 de imágenes grandes con esquinas de 10 px; móvil: carrusel con flechas) · columna derecha: título grande, marca (logo en tarjeta), SKU, **recuadro destacado** (colección/promoción), descripción corta, **precio naranja grande** (~32 px), selector de **color con círculos**, cantidad (− 1 +) en píldora, **"Add to cart" naranja + "Buy now" negro**, enlaces "Compare" / "Add to wishlist", acordeones ("Shipping and returns", "Product care"). Debajo: tarjeta blanca con "Product details" (tabla clave/valor con separadores finos) junto a "Description" (imagen + texto + viñetas naranjas), tarjeta "About brand" con compartir (círculos de redes) y botón "Contact us"; luego productos relacionados.

### Carrito / Checkout
Banner con **pasos** "Shopping cart → Checkout → Order complete" (el activo subrayado en naranja). Vacío: icono de carrito con ✕ grande y tenue, "Your cart is currently empty.", texto y botón "Return to shop". (Carrito con partidas: tabla de líneas a la izquierda y totales a la derecha — a construir con el patrón de tarjetas blancas.)

### Mi cuenta (login)
Banner con título gigante y breadcrumb · dos columnas separadas por una línea vertical: **Login** (usuario/correo, contraseña con ojo, botón naranja de ancho completo, "Remember me", "Lost your password?" en naranja) y **Register** (texto + botón gris).

### Otros
Comparar (lista vacía con el mismo patrón), blog, contacto, "about us", gift cards.

## 4. Tarjeta de producto
Tarjeta blanca radio 10 px, sin borde. Imagen centrada sobre fondo blanco (~65 % de la altura), corazón de favoritos arriba a la derecha, etiquetas apiladas arriba a la izquierda. Debajo: **nombre** (Urbanist 700, 14 px) con **valoración** a la derecha (5.0 ★ amarilla), **categoría** en gris, **precio** naranja 16 px/600 (precio anterior tachado en gris + precio nuevo), **puntos de color** a la derecha del precio. En móvil el botón naranja de ancho completo "Add to cart" va siempre visible; en escritorio aparece al pasar el cursor.

## 5. Comportamientos observados
Buscador con sugerencias en vivo · cajón lateral de login y de carrito (mini-carrito) · carrusel con flechas y puntos · cajón de filtros en móvil · barra inferior fija en móvil · contadores en iconos de comparar/favoritos/carrito · página con scroll horizontal accidental de ~50 px en el demo (cajón de login fuera de pantalla): **no replicar**.

## 6. Cómo se traduce a nuestro sistema

| Elemento del demo | Nuestro equivalente |
|---|---|
| Moneda EUR / selector de país | **Selector de tienda MX/US** + aviso `marketContext` (ya existe en la API) |
| Categorías con iconos y fotos | `categoryTree` (imagen de categoría ya existe; icono: por definir) |
| Filtros precio/marca/color | `products(filter)` + atributos (color como círculos); **marca**: no existe en el catálogo → decisión pendiente |
| Valoraciones ★ | **No existe** (sin reseñas en el modelo) → omitir hasta tener reseñas |
| Comparar | **No existe** → omitir (o Fase posterior) |
| Selector de color | variaciones por atributo (ya existen) |
| **Cantidad** | **Venta por metro / por rollo / pieza** (nuestro negocio): selector de modo (corte o rollo), paso de 0.5 m, mínimo/máximo, precio por rollo — componente propio, no está en el demo |
| Precio | por tienda: MX con IVA incluido, US sin impuesto (se muestra "+ impuestos") |
| Disponibilidad | `stockStatusIn(market)` |
| Pedidos personalizados | sección propia (solicitud → cotización) — no está en el demo |
| Blog, marcas, colecciones, video, app móvil | **Fuera del alcance de la Fase 9** salvo que se pida (no hay modelo de datos); se deja el hueco visual como bloques de contenido editables más adelante |
| Textos / SEO | `seo()` y `seoRoutes` de la API; i18n es-MX / en-US |

## 7. Decisiones que necesito del usuario antes de construir
1. **Marca del Storefront**: nombre y logotipo propios (no "woodmart"). ¿Hay logotipo y paleta definitiva? Por defecto: mismo acento `#F59A57` y nombre "Cronos".
2. **Contenido de la portada**: ¿hero/colecciones con tus fotos reales de listones y moños? Sin ellas uso imágenes de marcador neutras.
3. **Secciones sin respaldo en datos** (marcas, valoraciones, comparar, blog, video, app): ¿omitir, o crear los módulos de datos más adelante?
4. Contenedor del Storefront: **SPA en React + Vite** como el admin (propuesto) o **SSR** (Next.js) por SEO. El SEO ya está resuelto en la API (`seo`, sitemap, robots), pero una SPA exige pre-render para que los buscadores lean los metadatos.
