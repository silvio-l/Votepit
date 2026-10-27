// machine-drafted, needs native review
import type de from './inboxPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar la bandeja de entrada.',
  title: 'Bandeja de entrada',
  subtitle: 'Respuestas a tus solicitudes de soporte y novedades de Votepit.',

  notificationsHeading: 'Notificaciones ({count})',
  noNotifications: 'Aún no hay notificaciones.',
  unreadBadge: 'Nuevo',
  dismiss: 'Eliminar',
  dismissAriaLabel: 'Eliminar "{title}" de la bandeja de entrada',

  machineTranslatedLabel: 'Traducción automática',
  showOriginalLink: 'Mostrar original',
  showTranslationLink: 'Mostrar traducción',
  translationUnavailableHint: 'La traducción no está disponible en este momento.',
} satisfies typeof de

export default es
