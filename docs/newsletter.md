# Boletín (suscripción con doble confirmación)

**Alcance**: captura y gestión de la lista (alta, confirmación, baja, prueba de consentimiento, borrado). **No incluye el envío de campañas**: para eso exporta los confirmados (admin → *Boletín* → «Exportar confirmados (CSV)») a la herramienta de envío (Mailchimp, Brevo, Resend…) o se construye después un módulo de campañas. Quien envíe debe usar la baja de la tienda o sincronizar las bajas de vuelta.

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
