import type { Locale } from './config'

const es = {
  skip: 'Saltar al contenido',
  search: 'Buscar', searchPlaceholder: 'Buscar listones, moños…', searchResults: 'Resultados para «{q}»', noResults: 'No encontramos resultados.',
  cart: 'Carrito', account: 'Mi cuenta', login: 'Iniciar sesión', register: 'Crear cuenta', wishlist: 'Favoritos', shop: 'Tienda', menu: 'Menú', close: 'Cerrar', categories: 'Categorías',
  home: 'Inicio', allProducts: 'Todos los productos', showing: 'Mostrando {from}–{to} de {total}', sortBy: 'Ordenar', filters: 'Filtros', price: 'Precio', min: 'Mín.', max: 'Máx.', apply: 'Aplicar', clear: 'Quitar filtros',
  sort: { NEWEST: 'Más recientes', PRICE_ASC: 'Precio: menor a mayor', PRICE_DESC: 'Precio: mayor a menor', NAME: 'Nombre', RELEVANCE: 'Relevancia' },
  next: 'Siguiente', previous: 'Anterior', page: 'Página',
  inStock: 'Disponible', backorder: 'Sobre pedido', outOfStock: 'Agotado', addToCart: 'Agregar al carrito', adding: 'Agregando…', added: 'Agregado al carrito', viewCart: 'Ver carrito',
  perMeter: 'por metro', perPiece: 'por pieza', perRoll: 'por rollo', roll: 'Rollo completo', cut: 'Por metro', metres: 'metros', pieces: 'piezas', rollOf: 'Rollo de {n} m',
  buyBy: 'Comprar por', length: 'Largo (m)', quantity: 'Cantidad', stepHint: 'En múltiplos de {step} m, mínimo {min} m', minHint: 'Mínimo {min}', taxIncluded: 'IVA incluido', plusTax: '+ impuestos', lineTotal: 'Total',
  description: 'Descripción', details: 'Detalles', sku: 'SKU', related: 'También te puede interesar', share: 'Compartir',
  emptyCart: 'Tu carrito está vacío.', emptyCartText: 'Agrega listones y moños para empezar.', returnToShop: 'Volver a la tienda', subtotal: 'Subtotal', discount: 'Descuento', shipping: 'Envío', tax: 'Impuestos', total: 'Total', shippingAtCheckout: 'Se calcula al pagar', coupon: 'Cupón', applyCoupon: 'Aplicar cupón', removeCoupon: 'Quitar', checkout: 'Finalizar compra', remove: 'Quitar', update: 'Actualizar',
  stepCart: 'Carrito', stepCheckout: 'Pago', stepDone: 'Pedido completo',
  hello: 'Hola', outsideTitle: 'Estás viendo otra tienda', switchStore: 'Cambiar de tienda', store: 'Tienda', language: 'Idioma',
  notFound: 'No encontramos esa página.', notFoundText: 'El enlace puede estar roto o la página ya no existe.', error: 'Algo salió mal.', retry: 'Reintentar',
  freeShippingFrom: 'Envío gratis desde {amount}', rights: 'Todos los derechos reservados.', testMode: 'Modo de pruebas: no se cobra dinero real.',
  newsletter: 'Boletín', contact: 'Contacto', customOrder: 'Pedido personalizado', trackOrder: 'Rastrear pedido',
  replacedMode: 'Cada producto se compra de una sola forma: reemplazamos tu selección anterior (corte o rollo).',
  noPaymentMethods: 'No hay métodos de pago disponibles para esta tienda por ahora. Inténtalo más tarde.',
  email: 'Correo electrónico', password: 'Contraseña', firstName: 'Nombre', lastName: 'Apellido', phone: 'Teléfono', signIn: 'Entrar', signUp: 'Crear cuenta', signOut: 'Cerrar sesión', noAccount: '¿No tienes cuenta?', haveAccount: '¿Ya tienes cuenta?',
  myOrders: 'Mis pedidos', noOrders: 'Aún no tienes pedidos.', order: 'Pedido', status: 'Estado', date: 'Fecha', orderDetail: 'Detalle del pedido', timeline: 'Seguimiento', items: 'Artículos', shipTo: 'Enviar a', trackTitle: 'Rastrear un pedido', trackText: 'Escribe el número de pedido y el correo con el que compraste.', orderNumber: 'Número de pedido', track: 'Rastrear', trackNotFound: 'No encontramos un pedido con esos datos.',
  shippingAddress: 'Dirección de envío', recipient: 'Nombre de quien recibe', street: 'Calle', exteriorNo: 'Núm. exterior', interiorNo: 'Núm. interior', neighborhood: 'Colonia', city: 'Ciudad', state: 'Estado', zip: 'Código postal', country: 'País', references: 'Referencias', payWith: 'Pagar con', continueToPayment: 'Continuar al pago', payNow: 'Pagar ahora', paymentFailed: 'No se pudo iniciar el pago. Inténtalo de nuevo.', checkingPayment: 'Confirmando tu pago…', thanks: '¡Gracias por tu compra!', thanksText: 'Te enviamos la confirmación por correo.', paymentPending: 'Tu pago aún se está procesando. Revisa tu correo o el estado del pedido en unos minutos.', paymentFailed2: 'El pago no se completó.', summary: 'Resumen', wishlistEmpty: 'Aún no tienes favoritos.', save: 'Guardar en favoritos', saved: 'En favoritos', loginToSave: 'Inicia sesión para guardar favoritos.', forgotPassword: '¿Olvidaste tu contraseña?', required: 'Obligatorio',
}

const en: typeof es = {
  skip: 'Skip to content',
  search: 'Search', searchPlaceholder: 'Search ribbons, bows…', searchResults: 'Results for “{q}”', noResults: 'We found no results.',
  cart: 'Cart', account: 'My account', login: 'Sign in', register: 'Create account', wishlist: 'Wishlist', shop: 'Shop', menu: 'Menu', close: 'Close', categories: 'Categories',
  home: 'Home', allProducts: 'All products', showing: 'Showing {from}–{to} of {total}', sortBy: 'Sort', filters: 'Filters', price: 'Price', min: 'Min', max: 'Max', apply: 'Apply', clear: 'Clear filters',
  sort: { NEWEST: 'Newest', PRICE_ASC: 'Price: low to high', PRICE_DESC: 'Price: high to low', NAME: 'Name', RELEVANCE: 'Relevance' },
  next: 'Next', previous: 'Previous', page: 'Page',
  inStock: 'In stock', backorder: 'On backorder', outOfStock: 'Out of stock', addToCart: 'Add to cart', adding: 'Adding…', added: 'Added to cart', viewCart: 'View cart',
  perMeter: 'per metre', perPiece: 'each', perRoll: 'per roll', roll: 'Whole roll', cut: 'By the metre', metres: 'metres', pieces: 'pieces', rollOf: '{n} m roll',
  buyBy: 'Buy by', length: 'Length (m)', quantity: 'Quantity', stepHint: 'In multiples of {step} m, minimum {min} m', minHint: 'Minimum {min}', taxIncluded: 'Tax included', plusTax: '+ tax', lineTotal: 'Total',
  description: 'Description', details: 'Details', sku: 'SKU', related: 'You may also like', share: 'Share',
  emptyCart: 'Your cart is currently empty.', emptyCartText: 'Add ribbons and bows to get started.', returnToShop: 'Return to shop', subtotal: 'Subtotal', discount: 'Discount', shipping: 'Shipping', tax: 'Tax', total: 'Total', shippingAtCheckout: 'Calculated at checkout', coupon: 'Coupon', applyCoupon: 'Apply coupon', removeCoupon: 'Remove', checkout: 'Checkout', remove: 'Remove', update: 'Update',
  stepCart: 'Cart', stepCheckout: 'Checkout', stepDone: 'Order complete',
  hello: 'Hello', outsideTitle: 'You are browsing another store', switchStore: 'Switch store', store: 'Store', language: 'Language',
  notFound: 'We could not find that page.', notFoundText: 'The link may be broken or the page no longer exists.', error: 'Something went wrong.', retry: 'Try again',
  freeShippingFrom: 'Free shipping over {amount}', rights: 'All rights reserved.', testMode: 'Test mode: no real money is charged.',
  newsletter: 'Newsletter', contact: 'Contact', customOrder: 'Custom order', trackOrder: 'Track order',
  replacedMode: 'Each product is bought one way only: we replaced your previous choice (cut or roll).',
  noPaymentMethods: 'No payment methods are available for this store right now. Please try again later.',
  email: 'Email', password: 'Password', firstName: 'First name', lastName: 'Last name', phone: 'Phone', signIn: 'Sign in', signUp: 'Create account', signOut: 'Sign out', noAccount: 'No account yet?', haveAccount: 'Already have an account?',
  myOrders: 'My orders', noOrders: 'You have no orders yet.', order: 'Order', status: 'Status', date: 'Date', orderDetail: 'Order details', timeline: 'Tracking', items: 'Items', shipTo: 'Ship to', trackTitle: 'Track an order', trackText: 'Enter the order number and the email you used to buy.', orderNumber: 'Order number', track: 'Track', trackNotFound: 'We could not find an order with those details.',
  shippingAddress: 'Shipping address', recipient: 'Recipient name', street: 'Street', exteriorNo: 'Exterior no.', interiorNo: 'Interior no.', neighborhood: 'Neighborhood', city: 'City', state: 'State', zip: 'ZIP code', country: 'Country', references: 'Delivery notes', payWith: 'Pay with', continueToPayment: 'Continue to payment', payNow: 'Pay now', paymentFailed: 'The payment could not be started. Please try again.', checkingPayment: 'Confirming your payment…', thanks: 'Thank you for your order!', thanksText: 'We emailed you the confirmation.', paymentPending: 'Your payment is still being processed. Check your email or the order status in a few minutes.', paymentFailed2: 'The payment was not completed.', summary: 'Summary', wishlistEmpty: 'You have no favorites yet.', save: 'Save to wishlist', saved: 'In wishlist', loginToSave: 'Sign in to save favorites.', forgotPassword: 'Forgot your password?', required: 'Required',
}

export type Messages = typeof es
const all: Record<Locale, Messages> = { 'es-MX': es, 'en-US': en }

export const messages = (locale: Locale): Messages => all[locale]
export const fill = (text: string, values: Record<string, string | number>): string => text.replace(/\{(\w+)\}/g, (_, k: string) => String(values[k] ?? ''))
