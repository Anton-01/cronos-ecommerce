# Formulario de contacto: protección con Cloudflare Turnstile y correos

## Cómo se protege del spam (de la capa más barata a la más cara)

1. **Validación de campos** (nombre, correo, tema, mensaje de 10 a 4000 caracteres).
2. **Campo trampa** (`website`): invisible para personas; si llega con texto → spam.
3. **Tiempo de llenado**: el formulario manda la hora en que se mostró; un envío antes de 3 s → spam (`CONTACT_MIN_FILL_SECONDS`).
4. **Cloudflare Turnstile**: el navegador resuelve el reto (casi siempre sin que la persona haga nada) y la API **vuelve a verificarlo en el servidor** (`siteverify`) con el secreto, el token, la IP de la persona y la acción `contact`. Un token solo sirve una vez y por 5 minutos.
5. **Límites**: por IP (5/hora) y por correo (3/día) → se rechaza **sin guardar** (`RATE_LIMITED`); además `@throttle` de GraphQL (10 cada 10 min).
6. **Señales de contenido**: muchos enlaces, nombre que es un enlace, caracteres repetidos, alfabeto inesperado, frases típicas de spam, mensaje repetido.

Qué pasa con lo sospechoso: **se guarda con estado `spam` y la razón** (no se borra, para poder rescatar a una persona real) y **no se envía correo**; a quien lo mandó se le responde "enviado" igual, para que un bot no aprenda. Una sola señal débil (un cliente que pega tres enlaces de productos) **sí se entrega**, marcada; dos señales juntas → spam. Solo se rechaza abiertamente un reto de Cloudflare fallido (`HUMAN_CHECK_FAILED`) o una avalancha.

Si Cloudflare no responde (caída o error 5xx), el mensaje **pasa marcado** (`human_check = unavailable`) en lugar de bloquear a clientes reales; los límites y las señales siguen activos.

## Configurar Turnstile

1. Cloudflare → *Turnstile* → *Add widget*: dominio de la tienda, modo *Managed*. Da dos llaves.
2. API (`backend/.env`): `TURNSTILE_SITE_KEY=…` (pública) y `TURNSTILE_SECRET=…` (secreta). Opcional: `TURNSTILE_EXPECTED_HOSTNAME=tienda.com` para exigir que el reto se resolvió en tu dominio.
3. Sin `TURNSTILE_SECRET` no hay verificación (desarrollo): el formulario no muestra el widget y los mensajes quedan con `human_check = skipped`. La llave pública solo se ofrece si el secreto también está, para no dejar un widget decorativo.
4. Llaves de prueba de Cloudflare: sitio `1x00000000000000000000AA` + secreto `1x0000000000000000000000000000000AA` (siempre pasa); secreto `2x0000000000000000000000000000000AA` (siempre falla). Con ellas se probó de punta a punta contra Cloudflare real.
5. Si algún día se añade una política CSP al storefront debe permitir `script-src` y `frame-src` `https://challenges.cloudflare.com`.

## Correos (plantillas a considerar)

Se envían por el sistema de notificaciones existente (cola, registro `notification_log`, reintentos, dedupe por mensaje). Vistas en `backend/resources/views/mail/shop/` y textos en `backend/lang/{es-MX,en-US}/shop_mail.php`:

| Plantilla | Para quién | Idioma | Contenido |
|---|---|---|---|
| `contact_received` | La tienda (`ops_email` de la tienda, si no `contact_email`, si no `OPS_ALERT_EMAIL`) | El de la tienda | Asunto `[Contacto REF] Tema — Nombre`; **Reply-To = el cliente** (basta «Responder»); mensaje citado, tabla con nombre, correo, teléfono, pedido, tema, tienda/país y referencia; botón «Responder por correo». |
| `contact_receipt` | Quien escribió | El que estaba leyendo | «Recibimos tu mensaje (REF)», copia de lo enviado, referencia, horario de atención de la tienda; Reply-To = `contact_email` de la tienda. |

Lo que escribe el visitante entra a un correo en Markdown, así que **se escapa antes** (`NotifyAboutContact::plain/quote`): un enlace, imagen, encabezado o HTML escritos por el visitante salen como texto, nunca activos (hay prueba automática). Ver el resultado renderizado: `backend/tests/Feature/Contact/ContactTest.php`.
