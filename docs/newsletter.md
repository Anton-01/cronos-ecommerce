# Boletín (suscripción con doble confirmación)

**Alcance**: captura y gestión de la lista (alta, confirmación, baja, prueba de consentimiento, borrado) y **campañas enviadas con Resend** (sección al final). También se puede exportar a CSV (admin → *Boletín* → «Exportar confirmados»).

## Flujo

1. Alta en el pie de página (o casilla en el registro) → fila **pendiente** + correo `newsletter_confirm` con enlace que vale 7 días. Mientras esté pendiente **no se le escribe nada más**.
2. El enlace abre una página con un **botón** «Confirmar suscripción». Es un botón y no el enlace mismo a propósito: los antivirus y filtros de correo abren todos los enlaces y confirmarían (o darían de baja) a la gente solos.
3. Al confirmar → **confirmado**, se guarda cuándo y desde qué IP, y sale `newsletter_welcome` con el enlace de baja.
4. Baja: enlace que **nunca vence**, también con botón. Volver a suscribirse exige confirmar otra vez (nuevo consentimiento).

## Privacidad y abuso

- **Prueba de consentimiento**: fecha, IP, país y navegador de la solicitud, y fecha/IP de la confirmación. «Borrar datos» en el admin elimina la fila y esa prueba (derecho de supresión).
- **No revela quién está en la lista**: toda dirección válida recibe la misma respuesta («revisa tu correo»), sea nueva, pendiente o ya confirmada.
- **Anti-abuso**: Cloudflare Turnstile con su propia acción `newsletter` (un token del formulario de contacto no sirve), campo trampa, tiempo mínimo, límite por IP (10/h) y por dirección (3/día, contra quien quiera inundar un buzón ajeno), reenvío de la confirmación como máximo una vez cada 10 min. Quien ya inició sesión (registro) no pasa por Turnstile.
- La verificación «¿eres persona?» es ahora un servicio **compartido** (`Shared\Domain\HumanVerifier`, config `config/human.php`); las mismas llaves `TURNSTILE_*` sirven al contacto y al boletín.

## Configuración

- Interruptor en *Apariencia → Funciones* («Boletín»): oculta el formulario del pie.
- Permisos `newsletter.view` / `newsletter.manage` (rol admin; volver a ejecutar `RolesAndPermissionsSeeder`).
- Límites: `NEWSLETTER_MAX_PER_IP_HOUR`, `NEWSLETTER_MAX_PER_EMAIL_DAY`, `NEWSLETTER_MIN_FILL_SECONDS`.


## Campañas con Resend

Admin → *Boletín* → pestaña **Campañas**: redactar (Markdown por idioma, asunto y texto de vista previa), elegir audiencia (tiendas e idiomas; siempre solo **confirmados**), **previsualizar** el correo real, **enviar una prueba** a tu correo, y **enviar ahora o programar**.

**Configurar Resend**
1. En resend.com verifica el dominio desde el que envías (registros SPF y DKIM en tu DNS) y crea una API key.
2. `backend/.env`: `RESEND_API_KEY`, `NEWSLETTER_FROM="Tienda <news@tu-dominio.com>"` (el dominio debe ser el verificado), opcional `NEWSLETTER_REPLY_TO`.
3. Webhooks en Resend: endpoint `https://<tu-api>/webhooks/resend`, eventos `email.delivered`, `email.bounced`, `email.complained`; copia el *signing secret* a `RESEND_WEBHOOK_SECRET`. Sin él los rebotes y quejas no se registran (el admin lo avisa).
4. Un *worker* que procese la cola `campaigns` y el *scheduler* de Laravel (`php artisan schedule:work`) para las campañas programadas.
5. Los correos transaccionales (pedidos, contacto…) pueden salir también por Resend sin código: `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.resend.com`, `MAIL_PORT=587`, `MAIL_USERNAME=resend`, `MAIL_PASSWORD=<api key>`.

**Cómo envía** (diseñado para no mandar nada dos veces)
- Al enviar se **congela la lista**: una fila por suscriptor confirmado (única por campaña), numerada en lotes de 100. Enviar dos veces o reintentar un lote no duplica a nadie.
- Un trabajo por lote, separados 1 s (el límite por defecto de Resend son 2 peticiones/s), con `Idempotency-Key` `campaign:{id}:batch:{n}`: si un lote se reintenta tras un corte, Resend responde lo mismo en vez de reenviarlo.
- 429 y errores 5xx → se reintenta con esperas crecientes; 401/403 (llave mala) → los mensajes **quedan en cola** hasta corregirla; un 4xx por el contenido → ese lote se marca fallido y la campaña sigue.
- Quien se dio de baja entre que se congeló la lista y su turno **no recibe** el correo.
- Cancelar detiene lo que no se ha entregado a Resend; lo ya aceptado no se puede recuperar.

**Cumplimiento y reputación**
- Cada correo lleva enlace de baja visible, la dirección postal de la tienda (domicilio fiscal del mercado, o `NEWSLETTER_POSTAL_ADDRESS`) y las cabeceras `List-Unsubscribe` + `List-Unsubscribe-Post` (RFC 8058): Gmail y Apple Mail muestran su botón «Cancelar suscripción» y hacen un POST a `/newsletter/unsubscribe` sin abrir ninguna página.
- Un **rebote permanente** o una **queja de spam** sacan la dirección de la lista automáticamente; un rebote temporal no. No se rastrean aperturas ni clics (no hay píxeles ni enlaces espía).
- Las firmas de los webhooks (Svix) se verifican, con tolerancia de 5 minutos contra repeticiones.

**Probado**: 15 pruebas automáticas (lotes, idiomas, filtros, reintentos, cancelación, programación, permisos, firmas) y una prueba de punta a punta con el *worker* real y un servidor Resend simulado. **No probado contra Resend de verdad**: no hay API key en este entorno (el servicio sí responde; sin llave contesta 401), así que el primer envío real hay que hacerlo con «Enviar prueba».
