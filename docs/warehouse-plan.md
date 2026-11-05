# Plan: inventario por ubicación (anaqueles), módulo de almacén y QR

Estado: **propuesta para confirmar**. No se ha escrito código de esto todavía.
Origen del análisis: `MemoBullup/client-stock-studio` (commit `c8f36ec`, clonado en modo lectura) frente a nuestro sistema (`backend/src/Inventory`, `Catalog`, admin).

## 0. Límites del análisis (para no leer más certeza de la que hay)

- El repo de referencia es una app **TanStack Start + React + Supabase (Postgres)**. **No trae migraciones SQL ni el cuerpo de las funciones de base de datos**: la lógica crítica (`record_count`, `apply_stock_adjustment`, `save_capture`, `review_count`, la firma `defining_signature`, los bloqueos) vive en funciones RPC cuyo código **no está en el repositorio**; solo se ven sus firmas (`types.ts`) y cómo las llama la interfaz. Lo que sigue sobre esas funciones es **inferido** del tipado, de los nombres y del código cliente, y está marcado así.
- Lo que sí se leyó completo: el esquema (18 tablas, 11 enums, ~40 funciones), `lib/capture.ts`, `stock.ts`, `tree.ts`, `qr.ts`, `catalog.ts`, `importer.ts`, `alerts.ts`, `adjustments.*`, y las pantallas de captura, ficha del SKU, conteos, escaneo y etiquetas.
- No copiamos código: se extrae el *modelo y las reglas*, y se rediseñan sobre nuestra arquitectura (Laravel, hexagonal, Kardex inmutable, GraphQL).

## 1. Cómo modela el repo de referencia

**Catálogo**
- `product_types` (plantilla: nombre, unidad por defecto) ↔ `attributes` (código, tipo de dato `text|number|select|multiselect`, opciones, unidad por defecto) mediante `product_type_attributes` con dos banderas: **`is_required`** y **`is_variant_defining`** (qué atributos distinguen una variante: color, ancho…).
- `products` (el «modelo»: nombre, tipo, categoría, etiquetas de uso, estado de e-commerce `not_ready|in_preparation|ready`, `idempotency_key`) → `variants` (el **SKU**: `sku` autogenerado con prefijo y dígitos configurables, `base_unit_id`, `presentation_unit_id` + `units_per_presentation`, `record_status` borrador/registrado/archivado, `review_status`, `photo_status`, `defining_signature`, **`version`**, `idempotency_key`).
- `variant_attribute_values` guarda el valor **y su estado**: `value | not_applicable | pending` (un dato desconocido no es lo mismo que «no aplica»; hay un cálculo de «datos faltantes»).
- `units`: tabla con `kind` (`inventory` = pieza, rollo, paquete / `measure` = metro, gramo…) y `allows_decimal`.
- Alta rápida: la persona elige el tipo, escribe el producto y marca **combinaciones de dos ejes (colores × medidas)**; se crean las variantes marcadas. `check_variant_duplicates` compara la **firma** (atributos definitorios normalizados sin acentos/mayúsculas) y bloquea combinaciones completas repetidas, avisa de posibles duplicados parciales. Importación masiva Excel/CSV (hasta 1000 filas, 60 variantes por producto) que reutiliza el mismo guardado. «Presentación con contenido» (rollo de 25 m): permite contar «2 rollos + 5 m» solo si se conoce el factor; si no, no se inventa la conversión.

**Almacén**
- `locations`: **árbol** (padre, profundidad, `code`, orden, activo) con profundidad máxima configurable; mover valida ciclos y profundidad. Borrar exige vaciar.
- `stock_levels`: **(variante, ubicación) → cantidad**, con quién y cuándo contó. **Ausencia de fila = «pendiente de conteo»** (distinto de 0).
- `stock_counts` (conteos, con `session_id`, estado `confirmed|in_review|rejected`, `idempotency_key`, `capture_ref`, presentaciones completas + sueltos): un **segundo conteo en la misma ubicación no suma, queda en revisión** para el administrador (`review_count`).
- `stock_adjustments` (**solo inserciones**: cantidad previa, nueva, motivo, quién) vía `apply_stock_adjustment(modo set|increase|decrease)`; `stock_adjustment_requests` (el editor pide, el admin aprueba, con propuesta de IA opcional); `correction_requests`.
- `stock_minimums` por (variante, ubicación) → alertas «por debajo del mínimo» (`low_stock_summary`, `list_stock_minimums`).
- `audit_log` (tabla, acción, datos antes/después), `cost_history`.
- **QR**: la URL lleva **el UUID interno de la variante** (`/q/<id>`), nunca nombre ni cantidades; destino estable que exige sesión y abre la ficha; la base de la URL es configurable (avisa si es temporal); etiquetas con tamaño en mm configurable, hasta 500 por lote; el escaneo con cámara reconoce `/q/` o `/inventario/` o el UUID pelado.
- Roles: `admin` y `editor` (RLS en BD). Idempotencia en todas las escrituras (`idempotency_key` generada por el cliente).

## 2. Qué tenemos nosotros hoy (corrigiendo la premisa)

Ya existe infraestructura **a nivel de almacén**, y es más fuerte que la de referencia en integridad:
- `warehouses` (por mercado), `stock_levels(producto, almacén)` con `on_hand`, `reserved`, `available` generada, **Kardex `inventory_movements` solo-inserción** (trigger que impide UPDATE/DELETE/TRUNCATE, saldo posterior, **cadena de hashes** por producto+almacén, usuario, IP, país, zona horaria), compensaciones únicas, **reservas con caducidad** (carrito/pedido), bloqueo `FOR UPDATE` del nivel, orden fijo de bloqueos en traspasos, `inventory:reconcile`, evento `StockLow` con umbral por producto, aislamiento de existencias por tienda (MX/US), conteo físico como ajuste.
- **No existe**: ubicaciones dentro del almacén (zona/pasillo/anaquel/nivel), existencias ni movimientos **por ubicación**, conteos por sesión con revisión de conflictos, ningún rol/vista de operario de almacén, ningún QR ni etiqueta, mínimos por ubicación, notificaciones de existencias al personal, ni «tipos de producto» como plantillas.

## 3. Comparación y brechas

| Tema | Referencia | Nosotros hoy | Brecha / decisión |
|---|---|---|---|
| Identidad del artículo en el QR | UUID de la variante | ULID de producto (inmutable de hecho, pero también es la clave de la tienda) | **Nuevo `stock_code` inmutable** dedicado al almacén (§4.2) |
| Granularidad de existencias | variante × **ubicación** | producto × **almacén** | **Nuevas existencias por ubicación** |
| Historial | `stock_adjustments` (solo ajustes) + `audit_log` | **Kardex hash-encadenado** (todo) | Mantener el nuestro y **extenderlo a ubicación** |
| Pendiente vs. cero | sin fila = pendiente de conteo | `on_hand` por defecto 0 | Adoptar el concepto para ubicación («sin contar» ≠ 0) |
| Conteos | sesiones, ciego, conflicto → revisión | un `setCount` que ajusta | **Sesiones de conteo + revisión** |
| Ajustes por no admin | solicitud → aprobación | `inventory.manage` ajusta directo | **Solicitud/aprobación** para operarios |
| Mínimos / alertas | por (variante, ubicación) + resumen | umbral por producto + evento `StockLow` sin canal al personal | **Mínimos por ubicación y por almacén + avisos** |
| Concurrencia | `idempotency_key` + `version` + RPC en BD (inferido) | bloqueo de fila + reservas + idempotencia en pagos | Extender a ubicación **sin tocar el camino de compra** (§4.4) |
| Roles | admin / editor | RBAC con permisos finos | **Rol `warehouse` (operario) y `warehouse-lead`** (§4.6) |
| Tipos de producto | plantilla con atributos requeridos / definitorios | atributos globales y productos `variable` | **Plantillas de tipo** en Catálogo (§4.7) |
| Unidades | tabla `units` (inventario / medida, decimales) | enum de unidad de venta (pieza/metro) + rollo en `sales` | Mantener enum; añadir plantilla con unidad y largo de rollo |
| SKU | autogenerado, prefijo/dígitos configurables | escrito a mano (variaciones se auto-nombran) | **Generador de SKU** configurable |
| Alta masiva | Excel + combinaciones | variaciones por producto cartesiano | **Asistente de alta rápida** + importación (fase opcional) |
| Estados de datos | `value / not_applicable / pending` | valor o nada | Adoptar en la plantilla (fase 5) |
| Etiquetas | tamaño mm, lote 500, QR | no existe | **Etiquetas imprimibles** |

## 4. Diseño propuesto

### 4.1 Ubicaciones del almacén
Tabla `warehouse_locations` (por almacén): árbol (zona › pasillo › anaquel › nivel › caja) con `code` **inmutable** y único por almacén (p. ej. `A-03-2`), tipo, orden, capacidad opcional, `is_pickable`, `is_active`. Existe siempre una ubicación de sistema `RECEPCION` por almacén. **Editar nombre/capacidad no sobrescribe**: cada cambio inserta una fila en `warehouse_location_versions` (solo-inserción); el maestro refleja la última. Mover un nodo valida ciclos y profundidad máxima; una ubicación con existencias no se elimina, se desactiva.

### 4.2 Código inmutable para el QR (`stock_code`)
Cada producto con existencias propias (simple o variación) recibe al crearse un **`stock_code`**: 10 caracteres Crockford base32 aleatorios + 1 dígito de control (p. ej. `7K3M9Q2XA4-6`), único, **jamás editable (trigger de BD, igual que el Kardex) y nunca reutilizado** aunque se borre el producto (tabla de códigos emitidos). No deriva de nombre, SKU ni slug: renombrar, recategorizar o cambiar el SKU **no cambia el QR ya impreso**.
- QR = `https://<app>/w/<stock_code>` (solo el código; **sin** cantidades, costos ni nombre, como en la referencia). Exige sesión; sin sesión redirige a iniciar y regresa.
- La pantalla de escaneo muestra **todo lo necesario para el operario**: foto, nombre, atributos (color, ancho), SKU, unidad y rollo, existencias **por ubicación**, reservadas, disponibles, mínimo, pendientes de surtir, últimos movimientos y los botones de acción (mover, recibir, surtir, contar, solicitar ajuste).
- También un QR **por ubicación** (`/w/loc/<code>`) para el flujo «escanear anaquel → escanear artículo». Relleno del código de los productos existentes (backfill) en migración.

### 4.3 Existencias y movimientos por ubicación (inmutables)
- `location_stock(producto, ubicación) → quantity, version, counted_at`: derivado, reconstruible (como `stock_levels`). **Sin fila = sin contar.**
- `location_movements`: **solo-inserción** (triggers anti-UPDATE/DELETE/TRUNCATE), con `ulid`, tipo (`receive`, `putaway`, `move_out/in`, `pick`, `adjust`, `count_adjust`, `return`, `compensation`), cantidad con signo, **saldo posterior**, desde/hacia ubicación, `reference` (pedido, conteo, solicitud), **quién (usuario), cuándo (µs UTC), IP, país, zona, dispositivo**, nota, `idempotency_key` única y **cadena de hashes** por (producto, ubicación). Corregir = movimiento compensatorio (uno por original). Un traslado entre ubicaciones son **dos filas enlazadas** en una sola transacción.
- Invariante **verificable**: Σ(existencia por ubicación) respecto al `on_hand` del almacén; el comando `inventory:reconcile` se extiende para calcular diferencias y la vista las muestra.

### 4.4 Concurrencia y camino de compra (el punto delicado)
**Principio: el cliente que compra nunca espera a una operación de almacén.**
1. **Compra** (reserva → consumo): sigue tocando **solo** `stock_levels(producto, almacén)` con su bloqueo corto de hoy. No toma bloqueos de ubicación.
2. **Surtido** (opción recomendada): al pagarse el pedido se crean líneas de surtido; el operario **confirma el surtido** por ubicación (`pick`), que descuenta la ubicación y deja constancia. El saldo del almacén (libros) ya bajó al pagar; la **diferencia entre libros y suma de ubicaciones = «pendiente de surtir»** y se muestra explícita.
3. **Operaciones de almacén que cambian el total** (recibir, ajustar, merma) toman el bloqueo del nivel del almacén **y** el de la ubicación en **orden fijo** (almacén, luego ubicaciones por id ascendente) dentro de una transacción corta, con `lock_timeout` y reintento con espera; un traspaso interno entre ubicaciones **no** toca el saldo del almacén.
4. **Idempotencia obligatoria** (`idempotency_key` generada en el dispositivo): un doble toque o un reintento por mala señal no duplica.
5. **Conteos**: cada conteo guarda el valor del sistema **al empezar** y los movimientos posteriores se descuentan al aplicar (el conteo ciego no «pisa» ventas ni movimientos ocurridos durante el conteo). Segundo conteo en la misma ubicación → **revisión**, no se suma (regla de la referencia).
6. **Control optimista**: acciones que parten de una pantalla (ajuste «dejar en N») envían la `version` vista; si cambió, se rechaza con el valor nuevo para reconfirmar.
7. **Pruebas de carrera**: ráfagas en paralelo (varios procesos reales) de compras contra recepciones, traspasos y conteos; se exige cero sobreventa, cero saldos negativos y reconciliación exacta.

### 4.5 Alertas de existencias
- Mínimo y punto de reorden **por ubicación y por producto/almacén**, con estados `ok → bajo → crítico → agotado` y **histéresis** (no se repite el aviso por cada movimiento).
- Avisos en la vista (bandeja y contador), **correo** por el sistema de notificaciones (a la dirección de almacén de cada tienda) en cada **transición** de estado y **resumen diario**; evento `StockLow` ya existente se enriquece (cuántas quedan, dónde, mínimo).
- «Cuántas unidades quedan» visible en lector QR, listado y alertas.

### 4.6 Roles y vista de almacén
- Permisos nuevos: `warehouse.view` (ver), `warehouse.operate` (recibir, mover, surtir, contar, solicitar ajuste), `warehouse.approve` (aprobar ajustes y conflictos de conteo, editar mínimos), `warehouse.locations` (gestionar anaqueles), `warehouse.audit` (trazabilidad completa y exportación).
- Roles: **`warehouse`** (operario: ver + operar) y **`warehouse-lead`** (+ aprobar, ubicaciones, auditoría). Los administradores los heredan.
- El operario inicia sesión y **solo ve la sección «Almacén»** (el menú y las rutas se filtran por permiso; el resto de GraphQL se lo niega el servidor, no solo la interfaz). Sin acceso a precios, costos, pedidos ni clientes.
- Vista **móvil primero**, botones grandes, teclado numérico, escaneo con cámara y entrada manual del código, modo oscuro/alto contraste, teclado y lector de pantalla desde el diseño. Pantallas: **Escanear**, **Recibir**, **Mover**, **Surtir**, **Contar**, **Alertas**, **Ubicaciones** (árbol, QR de anaquel), **Trazabilidad** (línea de tiempo por producto / ubicación / empleado / fecha / tipo, antes → después, quién, desde dónde, indicador de integridad de la cadena, exportación CSV), **Etiquetas** (lote, tamaño en mm).

### 4.7 Tipos de producto y alta de listones
- `product_types`: plantilla (nombre, unidad de venta, paso y mínimo de corte, **largo de rollo**, venta por corte/rollo) + sus atributos con `is_required` y `is_variant_defining` (listón: color, ancho, material, acabado; moño: color, tamaño).
- **Asistente de alta rápida**: tipo → nombre/categoría → marcar combinaciones (color × ancho) → se crean las variaciones con **SKU autogenerado** (prefijo y dígitos en ajustes), `stock_code` y firma antidup (reutiliza nuestro `combination hash`, normalizado sin acentos); ubicación y conteo inicial opcionales en el mismo paso; borrador → publicado.
- Estados de dato `value / not_applicable / pending` y «datos faltantes» (fase final, opcional). Importación Excel/CSV (opcional).

## 5. Plan de trabajo por fases

Cada fase termina con pruebas verdes, documentación y commit; no se avanza si falla la anterior.

1. **Cimientos de ubicación** — migraciones (`warehouse_locations` + versiones, `location_stock`, `location_movements` con triggers y hashes, `stock_code` + tabla de códigos emitidos + backfill), dominio y servicios (`LocationLedger`, reglas, reconciliación ampliada), eventos. *Pruebas*: inmutabilidad, cadena de hashes, árbol (ciclos/profundidad), backfill, invariantes.
2. **Operaciones y concurrencia** — recibir, mover, surtir, ajustar, sesiones de conteo + revisión, solicitudes de ajuste, idempotencia, control optimista; integración con pedidos (líneas de surtido). *Pruebas*: ráfagas paralelas reales contra el camino de compra; cero sobreventa.
3. **Alertas** — mínimos, estados con histéresis, correo + bandeja + resumen diario, plantillas es/en.
4. **API y roles** — GraphQL de almacén, permisos y roles `warehouse` / `warehouse-lead`, pruebas de acceso (el operario no alcanza nada fuera de almacén).
5. **Vista de almacén (admin)** — todas las pantallas del §4.6, escaneo con cámara, etiquetas imprimibles y QR de ubicación, trazabilidad; revisión con axe/teclado desde el principio.
6. **Tipos de producto y alta rápida** — plantillas, asistente, SKU autogenerado, firma antidup; (opcional) importación y estados de dato.
7. **Cierre** — documentación, guía de operación, pruebas de extremo a extremo en navegador, y la **revisión manual con teclado y lector de pantalla** que dejamos pendiente, ahora incluyendo la vista de almacén.

Tamaño estimado: fases 1–4 son backend (grandes pero acotadas, ~las dos terceras partes del esfuerzo); fase 5 es la más visible; fase 6 es independiente y puede ir en paralelo o después.

## 6. Decisiones que necesito de ti

1. **Surtido (4.4-2)**: ¿el operario **confirma el surtido** de cada pedido (recomendado: el pago nunca espera a una escalera de bloqueos y queda trazado quién sacó qué), o prefieres que **al pagar se descuente solo de una ubicación asignada automáticamente** (más simple, pero acopla la compra a las ubicaciones)?
2. **Código QR**: ¿confirmas formato `10 caracteres + dígito de control`, solo para productos con existencias propias (simples y variaciones), más un QR por **anaquel**?
3. **Roles**: ¿los dos roles (`warehouse` operario y `warehouse-lead` supervisor) y que **los ajustes de operarios pasen por aprobación**, como en la referencia? ¿Qué umbral de ajuste exige aprobación (cualquiera, o solo mayores a N unidades / X %)?
4. **Alertas**: ¿a quién llegan los correos (una dirección por almacén/tienda, o por usuario), y qué niveles de aviso quieres (bajo / crítico / agotado)?
5. **Alcance de la fase 6**: ¿incluimos importación Excel y los estados `pendiente / no aplica` en esta entrega o los dejamos para después?
6. **Profundidad del árbol**: ¿qué niveles usan hoy en el almacén (zona › pasillo › anaquel › nivel)? Lo dejo configurable, pero me ayuda a sembrar datos de ejemplo.


## 7. Decisiones confirmadas por el cliente (reemplazan los valores recomendados)

| Tema | Decisión | Dónde se aplica |
|---|---|---|
| Surtido | El operario **confirma el surtido de cada pedido**. | Hecho (fase 2: `PickLists`). La lista de pendientes sale del Kardex. |
| Código QR | Formato **empresarial y sencillo**, legible a la distancia de un anaquel. Existe para **todo artículo/producto** que se dé de alta, **haya o no existencias y esté activo o no**; esa lógica (estado, existencias, activo/inactivo) vive en la **lectura** del QR, no en el código impreso. Se puede **descargar como imagen**, **mandar a imprimir** y **configurar su estilo desde el administrador**. | Fase 5 (etiquetas) con ajustes de estilo guardados con historial. El `stock_code` ya se asigna a todo producto de tipo simple/variación (fase 1). |
| Aprobaciones | Solo **a partir de cierto monto o porcentaje** (también por unidades). **Configurable desde el administrador**, igual que la **vista de aprobaciones**, los **correos de aprobación** y el **proceso de aprobar** con su trazabilidad. | Fase 3: `WarehousePolicies` (límites de unidades, % y monto, cada cambio queda como fila nueva; nunca se pueden apagar los tres), `ApprovalPolicy`, correo a quienes se suscriban a «Ajuste por aprobar». Fase 5: pantalla de aprobaciones y de políticas. |
| Alertas | **Módulo de alertas por proceso**: el administrador gestiona **qué usuarios reciben cada proceso**, solo agregando/quitando **usuarios del propio sistema**. | Fase 3: `AlertProcess` (10 procesos), `AlertSubscriptions` (solo personal activo con rol distinto de cliente; altas, cambios y bajas en bitácora inmutable), bandeja + correo por persona y por proceso. Fase 5: pantalla. |
| Fase 6 | **Se incluye ya** la importación Excel/CSV y los estados «pendiente / no aplica». | Fase 6 (sin cambios de alcance, sin diferirse). |
| Árbol de ubicaciones | Ejemplos reales: «Bodega › Pasillo B › Estante 01», «Bodega › Pasillo A › Estante 03 › Caja 02», «Tienda › Mostrador / Exhibidor». | `WarehouseDemoSeeder` (solo desarrollo). Profundidad máxima configurable (6 por omisión). |

### Referencias visuales recibidas (pantallas del repositorio de referencia)
- **Ubicaciones**: árbol con expandir/contraer, buscador, interruptor «Mostrar inactivas», insignia de nivel, conteo «N SKU con existencia» por nodo, botón «+» para hijo y menú de acciones; pestañas **Categorías | Ubicaciones**.
- **Categorías**: árbol de hasta 4 niveles, aviso de que «los productos sin categoría quedan como *Por clasificar* y pendientes de revisión» y que colores/medidas/acabados son **atributos, no categorías**.
Ambas se toman como guía para las pantallas de la fase 5 (mismo patrón de árbol, con nuestro diseño).
