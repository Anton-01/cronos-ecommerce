# Plataforma comercial: E-commerce + Almacén + POS

Documento técnico de arquitectura. Responde cómo debe quedar la **base de datos**, la **infraestructura**, la forma de **desarrollar** (repositorios, ramas, versiones) y el **despliegue en DigitalOcean** al costo mínimo, con tres sistemas que comparten una sola base de datos.

> Fuentes: el repositorio actual (`cronos-ecommerce`, fases 1 a 6) y el repositorio `Anton-01/amaranto-pos` (modelo de datos, rutas, `CONTEXT.md`, `DEPLOY_DIGITALOCEAN.md`). Los precios de DigitalOcean son los de lista que conozco; **verifícalos en su calculadora antes de contratar**.

---

## 1. Decisiones en una tabla

| Tema | Decisión | Por qué |
|---|---|---|
| Base de datos | **Un solo PostgreSQL 16 gestionado**, **tres esquemas**: `platform`, `commerce`, `pos` | Una venta de mostrador debe descontar existencias en la **misma transacción** que guarda el ticket; con bases separadas eso exigiría compensaciones y daría diferencias de inventario. |
| Quién escribe existencias | Solo el **núcleo** (paquete `cronos/kernel`), nunca SQL propio de cada app | Los kardex llevan cadena SHA-256 y bloqueos en orden fijo. Si tres apps los escribieran cada una a su manera, la cadena se rompería. |
| Permisos | Un **rol de base de datos por sistema** con permisos mínimos | Un error en el POS no puede tocar pedidos en línea, y viceversa. Los kardex ya son de solo inserción para todos. |
| Los dos inventarios | Dos **almacenes internos** (`channel = ecommerce` y `channel = pos`) dentro del almacén digital único | Reutiliza casi todo lo construido: ubicaciones, kardex, conteos, alertas y traspaso. Cada canal solo vende lo suyo. |
| Repositorios | **3 repositorios + 1 espejo de librería**: `cronos-platform` (Almacén + E-commerce + núcleo), `cronos-pos`, `cronos-infra`, y `cronos-kernel` (espejo automático de solo lectura) | Separa lo que tiene ritmo de cambio y riesgo distintos (el POS no puede caerse en horario de tienda) sin multiplicar repositorios para 1 o 2 desarrolladores. |
| Hosting | **1 Droplet de 4 GB + PostgreSQL gestionado + Spaces** al inicio; se separa en 2 Droplets cuando el e-commerce tenga tráfico | ≈ US$ 49 al mes. La arquitectura ya viene separada por contenedores, así que crecer es mover un contenedor, no reescribir. |
| Despliegue | GitHub Actions → imágenes en GHCR → `docker compose pull && up -d` por SSH, con migraciones primero | Sin costo adicional y con reversa por etiqueta de imagen. |

---

## 2. Los sistemas y el núcleo

```
        ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
        │ Tienda en    │   │  Admin SPA   │   │   POS web    │
        │ línea (Next) │   │ Almacén +    │   │ (3 cajas)    │
        │              │   │ Catálogo     │   │ + agente     │
        └──────┬───────┘   └──────┬───────┘   └──────┬───────┘
               │ GraphQL          │ GraphQL          │ REST + WebSocket
        ┌──────▼───────┐   ┌──────▼───────┐   ┌──────▼───────┐
        │ commerce API │   │ platform API │   │   pos API    │
        │ (pedidos,    │   │ (almacén,    │   │ (cajas,      │
        │ pagos, envío)│   │ catálogo)    │   │ ventas)      │
        └──────┬───────┘   └──────┬───────┘   └──────┬───────┘
               │                  │                  │
               └────────► cronos/kernel ◄────────────┘
                  catálogo · precios · inventario · kardex · traspasos
                                  │
               ┌──────────────────▼──────────────────┐
               │  PostgreSQL 16 (una base, 3 esquemas)│
               │  platform │ commerce │ pos           │
               └─────────────────────────────────────┘
```

**Quién es cada cosa**

- **Núcleo / Almacén (`platform`)**: catálogo de materiales, variantes, precios, impuestos, almacén digital con sus dos inventarios, ubicaciones, kardex, traspasos, conteos, alertas, usuarios del personal y auditoría. Es la **fuente de verdad**.
- **E-commerce (`commerce`)**: tienda en línea, carrito, pagos, pedidos, envíos, clientes, promociones, contenido y campañas.
- **POS (`pos`)**: cajas, turnos, ventas de mostrador, devoluciones, caja chica, tickets e impresión.

Los módulos actuales del backend se reparten así:

| Esquema | Módulos hoy en `backend/src` |
|---|---|
| `platform` | `Identity` (personal), `Catalog`, `Pricing`, `Markets`, `Inventory`, `Media`, `Settings`, `Audit`, `Notifications`, `Shared` |
| `commerce` | `Checkout`, `Orders`, `Payments`, `Shipping`, `CustomOrders`, `Wishlist`, `Promotions`, `Fraud`, `Contact`, `Newsletter`, `Content`, `Appearance`, `Seo`, `Reports` |
| `pos` | Nuevo (portado de `amaranto-pos`) |

Las pruebas de arquitectura que ya existen (`tests/Architecture`) garantizan que `Domain` y `Application` no dependan de infraestructura, lo que hace posible mover un módulo de lugar sin reescribirlo.

---

## 3. Base de datos

### 3.1 Esquemas, roles y permisos

| Rol de BD | Lo usa | Puede |
|---|---|---|
| `cronos_owner` | Solo el despliegue de migraciones del repositorio `cronos-platform` | Crear y alterar objetos de los tres esquemas |
| `app_platform` | API del Almacén y workers | Leer y escribir `platform.*`; leer `commerce.*` y `pos.*` (reportes) |
| `app_commerce` | API de la tienda | Leer `platform.*`; escribir `commerce.*`; **insertar** en kardex/reservas de `platform` solo a través del kernel |
| `app_pos` | API del POS | Leer `platform.*`; escribir `pos.*`; **insertar** en kardex de `platform` solo a través del kernel |
| `reporting` | Reportes y exportaciones | Solo lectura en todo |

Reglas que se mantienen para **todos** los roles (ya existen hoy en el repositorio):

- Los kardex (`inventory_movements`, `location_movements`) y las bitácoras inmutables tienen disparadores que impiden `UPDATE`, `DELETE` y `TRUNCATE`.
- El rol de aplicación no recibe permisos de `UPDATE/DELETE` sobre esas tablas (`REVOKE` ya está en las migraciones).
- Las tablas derivadas (`stock_levels`, `location_stock`) se pueden reconstruir con `inventory:reconcile --repair`.

Cómo se aplica en Laravel: cada app declara su `search_path` (`commerce,platform` o `pos,platform`) en `config/database.php`; los modelos y consultas no cambian. Mover las tablas actuales de `public` a `platform` es un `ALTER TABLE … SET SCHEMA`, rápido y sin copiar datos.

### 3.2 Identificadores y tiempo

- Las tablas compartidas usan **ULID** (`char(26)`), como el catálogo actual. El POS conserva `uuid` en sus tablas propias y referencia productos por ULID.
- Todo instante se guarda en UTC (`timestamptz`); la zona del negocio es `America/Mexico_City` en las tres capas (contenedor, Laravel, sesión de PostgreSQL), como ya estandariza el POS.
- Un solo `cronos_owner` ejecuta migraciones, siempre en el orden **platform → commerce → pos**.

### 3.3 Qué se agrega al modelo de datos

**Dos inventarios dentro del almacén digital.** Se reutiliza la tabla `warehouses` (hoy es el contenedor de existencias, ubicaciones, kardex y reservas):

```sql
ALTER TABLE platform.warehouses
  ADD COLUMN channel    varchar(12) NOT NULL DEFAULT 'ecommerce'
      CHECK (channel IN ('ecommerce','pos')),
  ADD COLUMN branch_id  bigint NULL;           -- sucursal dueña del inventario POS

-- Qué se vende por qué canal (un producto puede estar solo en tienda física, por ejemplo)
CREATE TABLE platform.product_channels (
  product_id char(26) NOT NULL REFERENCES platform.products(id),
  channel    varchar(12) NOT NULL CHECK (channel IN ('ecommerce','pos')),
  is_active  boolean NOT NULL DEFAULT true,
  PRIMARY KEY (product_id, channel)
);
```

Para el usuario se llaman **«Inventario E-commerce»** e **«Inventario POS»**; internamente son dos filas de `warehouses`. Cada uno tiene sus propias ubicaciones (bodega, mostrador, exhibidor), mínimos, conteos y alertas.

**Traspasos entre inventarios, con tránsito y seguimiento.** Hoy existe un traspaso inmediato (`transfer_out` / `transfer_in`). Se agrega el flujo completo:

```sql
CREATE TABLE platform.stock_transfers (
  id            char(26) PRIMARY KEY,
  code          varchar(20) UNIQUE NOT NULL,         -- TR-000123
  from_warehouse bigint NOT NULL REFERENCES platform.warehouses(id),
  to_warehouse   bigint NOT NULL REFERENCES platform.warehouses(id),
  status        varchar(16) NOT NULL DEFAULT 'requested'
      CHECK (status IN ('requested','approved','in_transit','received','partial','rejected','cancelled')),
  requested_by  char(26), approved_by char(26),
  note          varchar(500),
  idempotency_key varchar(80) UNIQUE,
  created_at timestamptz NOT NULL DEFAULT now(),
  shipped_at timestamptz, received_at timestamptz,
  CHECK (from_warehouse <> to_warehouse)
);
CREATE TABLE platform.stock_transfer_lines (
  transfer_id  char(26) REFERENCES platform.stock_transfers(id),
  product_id   char(26) REFERENCES platform.products(id),
  from_location char(26), to_location char(26),
  qty_requested numeric(14,3) NOT NULL CHECK (qty_requested > 0),
  qty_shipped   numeric(14,3) NOT NULL DEFAULT 0,
  qty_received  numeric(14,3) NOT NULL DEFAULT 0,
  diff_reason   varchar(200),
  PRIMARY KEY (transfer_id, product_id)
);
```

El saldo **en tránsito** no es una tabla aparte: es `qty_shipped − qty_received` de los traspasos abiertos, así que los libros siempre cuadran (sale del origen al enviar, entra al destino al recibir, y lo que falta se ve).

**El POS (`pos.*`)**, portado de las tablas actuales de `amaranto-pos`, con estos cambios:

| Tabla del POS actual | En el nuevo POS |
|---|---|
| `products`, `categories`, `stock_movements` | **Desaparecen**: se usa el catálogo y el kardex del núcleo |
| `cash_registers` | Se divide en `pos.registers` (las 3 cajas físicas) y `pos.shifts` (turnos con fondo, arqueo y cierre) |
| `orders`, `order_items` | `pos.sales`, `pos.sale_lines` (cantidades **decimales**, enlazadas al producto por ULID y al movimiento de kardex) |
| `petty_cash_transactions` | Igual, con sello HMAC inmutable |
| `ticket_configs`, `payment_methods`, `promotions` | Igual |
| `tables`, `table_sessions` | **Se eliminan** (restaurante) |
| — | Nuevas: `pos.returns`, `pos.return_lines`, `pos.credit_notes`, `pos.offline_batches` |

**Usuarios.** El personal vive en `platform` (una sola tabla de usuarios, roles y sesiones) y se agregan los roles `cashier` y `branch-manager` con permisos `pos.*`. Los tokens de Sanctum son filas en la base, así que cualquiera de las tres APIs valida el mismo token sin servicio de autenticación aparte. Los **clientes** de la tienda en línea siguen en `commerce`.

### 3.4 Cómo se vende en el mostrador sin descuadrar nada

Una venta del POS es **una sola transacción** de base de datos:

1. Se bloquea la fila de existencias de cada producto en el **inventario POS** (orden fijo por producto, sin interbloqueos).
2. Se valida que alcance (no se vende en negativo) y que el producto esté activo para el canal `pos`.
3. Se inserta el ticket y sus renglones en `pos.*`.
4. Se agrega el movimiento de kardex por renglón (con quién, desde dónde y el ticket como referencia) y se actualiza el nivel.
5. Se confirma. Si cualquier paso falla, no queda nada.

**Regla para ventas hechas sin internet.** Cuando vuelve la conexión, la venta ya ocurrió en el mundo físico: no se puede rechazar. Se registra aunque deje el inventario POS en negativo, se marca como *vendida sin existencia* y se genera una alerta para ajuste o conteo. La venta **en línea** nunca vende sin existencia (usa reservas con caducidad).

**Concurrencia entre sistemas.** El POS toca solo el inventario POS y la tienda en línea solo el inventario E-commerce, así que **no compiten** por las mismas filas salvo durante un traspaso, que bloquea ambos niveles en orden fijo (patrón que ya está probado con ráfagas de procesos reales en `WarehouseRaceTest`).

### 3.5 Cambios de esquema sin romper a los demás

Como tres apps comparten tablas, las migraciones del núcleo siguen la regla **expandir → desplegar → contraer**:

1. *Expandir*: solo se agregan columnas con valor por defecto, tablas o vistas nuevas. Nada se renombra ni se elimina.
2. Se despliegan las apps que usan lo nuevo.
3. *Contraer*: en una versión posterior se retira lo que ya nadie usa.

Cada repositorio de aplicación corre en su integración continua las **pruebas de contrato**: levanta PostgreSQL, aplica las migraciones del núcleo en la versión que declara y ejecuta sus pruebas. Si una migración del núcleo rompiera al POS, el POS lo detecta antes de desplegar.

---

## 4. El inventario dividido en la práctica

| Operación | Cómo funciona |
|---|---|
| **Venta en línea** | Reserva y consume solo del Inventario E-commerce. El stock visible en la tienda es el de ese inventario. |
| **Venta de mostrador** | Consume solo del Inventario POS (sección 3.4). |
| **Traspaso E-commerce ↔ POS** | Solicitud → aprobación (configurable por monto, como los ajustes) → envío (baja el origen) → recepción (sube el destino, con diferencias justificadas). Cada paso deja kardex y bitácora. |
| **Reubicación** | Mover una pieza entre ubicaciones del **mismo** inventario es un traslado interno (ya construido). Pasarla de un inventario a otro es un traspaso. |
| **Reposición** | Si el POS baja de su mínimo y el E-commerce tiene, el sistema sugiere el traspaso (alerta por proceso, ya existente). |
| **Devolución en mostrador** | Reingresa al Inventario POS (ubicación de recepción de la tienda) o se manda a merma con motivo. **No** vuelve sola al inventario en línea: eso exige un traspaso. |
| **Conteos, mermas, ajustes, QR** | Funcionan igual en ambos inventarios, cada uno con sus ubicaciones y políticas. |
| **Vista unificada** | Existencias por canal, total, reservado y en tránsito, por producto y variante. |

---

## 5. Carta de reglas del POS

Reglas que el nuevo POS **hereda tal cual** de `amaranto-pos` (se verificaron contra su `CONTEXT.md`):

| # | Regla |
|---|---|
| R1 | El precio público **incluye IVA**; el desglose es inverso: neto = precio ÷ (1 + IVA), IVA = precio − neto. La tasa es configurable. |
| R2 | Máximo **una promoción distinta por ticket**; descuentos directos y cupones quedan auditados en el ticket. |
| R3 | No se vende sin **caja abierta** con fondo inicial. Cierre **ciego** (el cajero cuenta sin ver lo esperado), cierre automático a las 21:00 en zona del negocio, cierre inmutable. Esperado = fondo + ventas en efectivo − retiros. |
| R4 | **Caja chica**: retiros con motivo de catálogo y sello HMAC-SHA256 inmutable; se puede verificar la integridad. |
| R5 | **Cancelar** una venta exige la contraseña de un administrador o gerente, revierte existencias, deja contramovimiento y bitácora con IP, navegador y motivo. Nada se borra. |
| R6 | El ticket guarda precio base, descuento, final e IVA **al momento** de la venta: cambiar precios después no altera el historial. |
| R7 | Diseño del ticket **versionado de solo inserción**; cada venta apunta al diseño vigente. Papel de 58 mm, impresión por agente local, reimpresión. |
| R8 | **Sin internet**: la venta se guarda en cola local con identificador único y se sincroniza de forma idempotente al volver. |
| R9 | La **merma** exige motivo de catálogo. Productos de combo pueden no llevar control de existencias. |
| R10 | Borrado lógico forense (quién y por qué); papelera con restauración solo para administradores. |
| R11 | Roles con permisos, bloqueo inmediato de usuario, 2FA por TOTP, límite de intentos de acceso, Turnstile. |
| R12 | Zona horaria `America/Mexico_City` de punta a punta. |

Reglas **nuevas** por vender materiales en una tienda con dos inventarios:

| # | Regla |
|---|---|
| N1 | La venta consume solo el inventario POS, en la misma transacción que el ticket (sección 3.4). |
| N2 | Se vende por **metro con paso de corte y mínimo**, por **rollo completo** con precio de rollo, o por pieza, con las mismas reglas del catálogo. |
| N3 | Devolución con ticket original, dentro del plazo configurable, con motivo; genera nota de crédito y reingresa o manda a merma. |
| N4 | El cajero encuentra el producto por **QR de almacén**, código de barras, SKU o nombre; el QR resuelve la variante exacta. |
| N5 | Hasta 3 cajas abiertas a la vez, cada una con su turno y su arqueo. |
| N6 | El POS puede **solicitar** un traspaso al almacén y ver (solo lectura) las existencias del otro inventario. |

**Se elimina**: mesas y cuentas abiertas. **Queda opcional y configurable**: el reparto de utilidad 70/30 del tablero, que es una regla del dueño del POS gastronómico y no necesariamente de este negocio.

---

## 6. Repositorios y forma de trabajar

### 6.1 Lo que se recomienda

| Repositorio | Contiene | Se despliega como |
|---|---|---|
| `cronos-platform` | Backend Laravel (módulos `platform` y `commerce`), Admin SPA (Almacén y Catálogo), tienda Next.js, y `packages/kernel` | 3 imágenes: API, admin (estático) y tienda |
| `cronos-kernel` | **Espejo automático de solo lectura** de `packages/kernel` (lo publica la integración continua) | Paquete de Composer con etiquetas `v1.x.y` |
| `cronos-pos` | API Laravel del POS, POS web (React), documentación del agente de impresión | 2 imágenes: API/Reverb y POS web |
| `cronos-infra` | Compose de producción, Caddy, scripts, flujos reutilizables de GitHub Actions, runbooks | No se despliega: orquesta |

**Por qué no un solo repositorio para todo:** el POS opera en horario de tienda y no puede romperse por un cambio de la tienda en línea; tiene su propio ritmo de versiones y su propio equipo de pruebas. **Por qué no un repositorio por cada sistema y el núcleo aparte, con todo separado:** para 1 o 2 personas cada repositorio extra suma integración continua, versionado y reuniones de coordinación; el núcleo y el e-commerce hoy comparten transacciones (el checkout consume reservas del almacén) y separarlos ahora costaría semanas sin dar beneficio. Las pruebas de arquitectura existentes dejan la puerta abierta a separarlos después.

### 6.2 El paquete `cronos/kernel`

Contiene exactamente lo que el POS y la tienda necesitan para **no reimplementar reglas**: catálogo (lectura), precios e IVA, unidades de venta (metro, corte, rollo), inventario (kardex, reservas, traspasos, códigos QR) y auditoría. Se edita dentro de `cronos-platform`; la integración continua lo publica como espejo; el POS lo declara con `composer require cronos/kernel:^1.4`. Subir de versión mayor requiere migración coordinada.

### 6.3 Flujo de trabajo

- **Ramas**: troncal con ramas cortas y revisión por solicitud de cambios; etiquetas de versión por repositorio.
- **Integración continua por repositorio**: pruebas, análisis estático, pruebas de arquitectura, pruebas de contrato contra el núcleo, construcción y publicación de la imagen en GHCR.
- **Propiedad**: el núcleo es el único dueño de las migraciones compartidas; commerce y POS solo agregan migraciones en su propio esquema.
- **Entornos**: desarrollo local con Docker, **staging bajo demanda** (un Droplet pequeño levantado desde una imagen guardada y una base `cronos_staging` en el mismo clúster, sin costo fijo) y producción.
- **Documentación viva**: cada repositorio tiene su `CONTEXT.md`/bitácora, como hoy.

---

## 7. Infraestructura en DigitalOcean

### 7.1 Topología inicial (todo en un Droplet)

```
Cloudflare (DNS, CDN, WAF, Turnstile; gratis)
        │  HTTPS
┌───────▼──────────────── Droplet 4 GB / 2 vCPU ───────────────────┐
│ Caddy (TLS automático)                                           │
│   tienda.dominio ─► Next.js (SSR)                                │
│   api.dominio    ─► commerce/platform API (PHP-FPM)              │
│   admin.dominio  ─► Admin SPA estático  (acceso restringido)     │
│   pos.dominio    ─► POS web + pos API   (acceso restringido)     │
│   ws.dominio     ─► Reverb (WebSocket del POS)                   │
│ Workers de colas · Planificador · Redis (1 instancia, 3 bases)   │
└───────┬──────────────────────────────────────┬───────────────────┘
        │ SSL, fuentes confiables              │ API S3
┌───────▼──────────────┐              ┌────────▼────────┐
│ PostgreSQL gestionado│              │ Spaces + CDN    │
│ (1 base, 3 esquemas) │              │ imágenes y      │
│ respaldos y PITR     │              │ respaldos       │
└──────────────────────┘              └─────────────────┘
```

**Presupuesto de memoria del Droplet de 4 GB** (estimación; se mide en staging): sistema y Docker 0.4 GB · PHP-FPM de las APIs 0.8 GB · Next.js 0.4 GB · Redis 0.15 GB · Reverb 0.1 GB · workers y planificador 0.4 GB · Caddy 0.05 GB ≈ **2.3 GB**, con holgura y 2 GB de swap. Al estar en un solo Redis (en desarrollo hay tres) se ahorran ~200 MB.

### 7.2 Costos mensuales por nivel (USD, precios de lista)

| Componente | A. Arranque | B. Recomendado al crecer | C. Crecimiento |
|---|---|---|---|
| Droplet de aplicaciones | 4 GB / 2 vCPU · 24 | 4 GB · 24 (tienda + API) | 4 GB · 24 |
| Droplet del POS y almacén | — | 2 GB / 2 vCPU · 18 | 4 GB · 24 |
| PostgreSQL gestionado | 1 GB / 10 GB · 15 | 2 GB · 30 | 4 GB / 2 vCPU · 60 |
| Spaces (imágenes + respaldos, CDN incluido) | 5 | 5 | 5 |
| Respaldos de Droplet (20 %) | 4.8 | 8.4 | 9.6 |
| Balanceador | — | — | 12 |
| **Total aproximado** | **≈ 49** | **≈ 85** | **≈ 135** |

Sin costo: Cloudflare (DNS, CDN, WAF, Turnstile, Access hasta 50 usuarios), GitHub (repositorios privados y 2 000 minutos de Actions al mes), GHCR, monitoreo de DigitalOcean, Resend hasta 3 000 correos al mes y planes gratuitos de Sentry. Aparte: dominio (≈ US$ 15 al año) y comisiones de pasarelas de pago.

**Qué no se recomienda ahorrar:** la base de datos. Hospedar PostgreSQL dentro del Droplet ahorra US$ 15 pero deja respaldos, actualizaciones y recuperación a cargo de quien administre; aquí viven inventario y dinero con registros inmutables. **Lo que sí se ahorra:** Redis y workers en el mismo Droplet, un solo Spaces, staging bajo demanda y Caddy en lugar de balanceador.

### 7.3 Cuándo pasar de nivel

| Señal | Acción |
|---|---|
| Memoria > 75 % sostenida, o la tienda en línea afecta el tiempo de respuesta del POS | De A a B: mover POS y Reverb a su propio Droplet |
| Conexiones a la base > 70 % del límite del plan | Activar el *connection pool* gratuito de DigitalOcean y subir a PostgreSQL de 2 GB |
| Base > 70 % del disco o CPU > 70 % | Subir el plan de la base |
| Disponibilidad exigida de 99.9 % | De B a C: segundo Droplet detrás del balanceador y nodo de respaldo de la base |

### 7.4 Seguridad

- La base solo acepta conexiones de la IP de los Droplets (*Trusted Sources*), con SSL obligatorio y un rol por sistema (sección 3.1).
- `admin.` y `pos.` detrás de **Cloudflare Access** (correo corporativo o IP de la tienda); la tienda en línea es pública.
- Cortafuegos de DigitalOcean: solo 80/443 y SSH desde IP fija. Secretos en archivos de entorno fuera del repositorio, rotables.
- Todo lo que ya existe se mantiene: CSP con nonce, límite de intentos, Turnstile, 2FA del personal, bitácoras inmutables.

---

## 8. Despliegue

1. **Construcción** (GitHub Actions, por repositorio): pruebas → análisis → imagen con etiqueta del commit → GHCR.
2. **Migraciones**: primero `cronos-platform` (rol `cronos_owner`); luego el despliegue de cada app. Siempre con la regla expandir/contraer, así una versión anterior de un app sigue funcionando mientras se actualiza el resto.
3. **Publicación**: por SSH con una llave de solo despliegue: `docker compose pull && docker compose up -d <servicio>`, verificando *healthcheck* antes de continuar.
4. **Reversa**: volver a la etiqueta anterior de la imagen. Una migración *expandir* no necesita reversa; una *contraer* solo se publica cuando la versión anterior ya no existe.
5. **Respaldos**: PostgreSQL gestionado con respaldo diario y recuperación a un punto en el tiempo (7 días); volcado semanal cifrado a Spaces; imágenes de Droplet semanales; **prueba de restauración trimestral** documentada.
6. **Vigilancia**: la conciliación nocturna de kardex (`inventory:reconcile`, `inventory:reconcile-locations`) ya alerta si algo no cuadra; se suman chequeos de disponibilidad externos y Sentry.

---

## 9. Ruta de implementación

| Etapa | Qué se hace | Se puede probar cuando termina |
|---|---|---|
| 1. Núcleo | Esquemas y roles, paquete `kernel`, identidad compartida, migración de datos | La API actual funciona igual sobre los esquemas nuevos |
| 2. Inventarios por canal | `channel`, `product_channels`, traspasos con tránsito, vista unificada | Una venta en línea descuenta solo del Inventario E-commerce; un traspaso mueve existencias con seguimiento |
| 3. POS | Portar reglas R1 a R12, agregar N1 a N6, interfaz, impresión, modo offline, devoluciones | Venta de mostrador de punta a punta contra el inventario POS |
| 4. Infraestructura | Provisionar, contenedores, integración y despliegue continuos, respaldos | Staging idéntico a producción |
| 5. Salida | Carga inicial, ensayo con la tienda, acompañamiento de dos semanas | Tienda abierta con las dos cajas y la tienda en línea |

Las etapas 3 y 4 pueden avanzar en paralelo con 2 personas.

---

## 10. Riesgos y decisiones abiertas

| Riesgo o decisión | Qué hacer |
|---|---|
| **Factura electrónica (CFDI 4.0)** quedó fuera del alcance acordado | Si se necesita, es un proyecto aparte (timbrado por PAC, catálogos del SAT, complemento de pago); conviene decidirlo antes de diseñar el ticket. |
| El POS actual es de **restaurante**; la tienda vende por metro | Las reglas de venta por metro, corte y rollo se toman del catálogo del núcleo, no del POS actual. |
| Ventas sin internet que dejan existencias negativas | Regla de la sección 3.4; requiere una política de ajuste con el gerente. |
| El **agente de impresión** local es software aparte | Se mantiene su protocolo (HTTP local, `127.0.0.1:9100`); hay que confirmar quién lo mantiene y cómo se instala en las 3 cajas. |
| Respaldos del POS actual van a **GCP** | Se cambia al almacenamiento compatible con S3 de DigitalOcean (Spaces). |
| Conexiones a PostgreSQL con varias apps y workers | Activar el *connection pool* de DigitalOcean y limitar workers; medir en staging. |
| Un cambio mayor del núcleo afecta al POS | Versionado semántico del kernel, pruebas de contrato y expandir/contraer. |
| Costos en dólares | DigitalOcean factura en USD; reservar margen cambiario en la propuesta. |
