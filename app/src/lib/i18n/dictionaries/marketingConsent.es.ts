// machine-drafted, needs native review
import type de from './marketingConsent.de'

const es = {
  profileHeading: 'Novedades de producto y ofertas',
  profileHint:
    'Correos ocasionales sobre nuevas funciones y ofertas de Votepit. Voluntario y revocable en cualquier momento. La confirmación se envía a tu dirección de correo de notificaciones confirmada.',
  toggleLabel: 'Recibir novedades de producto y ofertas por correo',
  statusNone: 'No activado.',
  statusPending:
    'Confirmación pendiente. Te hemos enviado un enlace de confirmación por correo. El enlace es válido durante 48 horas.',
  statusConfirmed: 'Activo. Puedes darte de baja en cualquier momento.',
  statusWithdrawn: 'No activado.',
  needsNotificationEmail:
    'Confirma primero una dirección de correo de notificaciones para que podamos enviarte la confirmación allí.',
  saveError: 'No se pudo guardar el ajuste. Inténtalo de nuevo.',
  loadError: 'No se pudo cargar tu ajuste.',
  signupCheckboxLabel:
    'Quiero recibir novedades de producto y ofertas de Votepit por correo (opcional, revocable en cualquier momento).',
  signupNotice:
    'Usamos tu dirección de correo para recomendaciones de productos propios y similares de Votepit. Puedes oponerte en cualquier momento; los únicos costes son los de transmisión según las tarifas básicas.',
  confirming: 'Confirmando…',
  doneTitle: 'Novedades de producto confirmadas',
  doneBody:
    'Gracias. A partir de ahora recibirás novedades de producto y ofertas de Votepit por correo. Puedes revocarlo en cualquier momento en los ajustes de tu perfil.',
  errorTitle: 'Confirmación no posible',
  invalidOrExpired:
    'El enlace no es válido o ha caducado. Solicita una nueva confirmación en los ajustes de tu perfil.',
  confirmFailedFallback: 'No se pudo completar la confirmación. Inténtalo más tarde.',
  backToProfile: 'Volver al perfil',
} satisfies typeof de

export default es
