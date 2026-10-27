// machine-drafted, needs native review
import type de from './submitPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar el formulario en este momento. Inténtalo de nuevo.',
  memberSuspendedError: 'Actualmente tienes suspendida la posibilidad de enviar contenido.',
  backToList: 'Volver al tablero',
  heading: 'Enviar una nueva idea',
  subtitle: 'Un título claro y un párrafo breve: texto sin formato.',
  honeypotLabel: 'Sitio web',
  titleLabel: 'Título',
  titlePlaceholder: 'Título breve y conciso',
  titleHint: '{count}/200 caracteres',
  duplicateHintQuestion: '¿Ya existe esta idea?',
  duplicateHintLead: 'Apoyar una idea existente tiene más peso que añadir una segunda.',
  votesCount: '{count} votos',
  votesCountSingular: '{count} voto',
  submitAnywayButton: 'Enviar de todos modos como idea nueva',
  bodyLabel: 'Descripción',
  bodyPlaceholder: '¿De qué se trata? ¿Qué debería cambiar?',
  bodyHint: '{count}/500 caracteres (mín. 10)',
  submitting: 'Enviando…',
  submit: 'Enviar idea',
  tagsSuggested: 'Sugerido',
  tagsLabel: 'Etiquetas (opcional)',
  tagsHint:
    'Elige hasta {count} etiquetas existentes. Las nuevas etiquetas las crea la moderación.',
  tagsLimitReached: 'Has alcanzado el número máximo de etiquetas. Quita una para elegir otra.',
  duplicateAiMatch: 'Coincidencia IA',
  guestNotice:
    'Estás enviando sin cuenta. Tu idea aparece como «Invitado». No se permiten enlaces y no recibirás notificaciones. Solo podrás volver a encontrar tus ideas en este dispositivo; para ello guardamos una cookie tras el envío.',
  guestThanksPendingTitle: '¡Gracias por tu idea!',
  guestThanksPendingBody:
    'Tu idea está pendiente de aprobación por la moderación. Mientras espera, puedes retirarla abajo en este dispositivo. Si se rechaza, también verás el motivo allí.',
  guestThanksPublishedTitle: '¡Gracias por tu idea!',
  guestThanksPublishedBody: 'Tu idea ya es visible. Sin cuenta ya no puedes editarla ni retirarla.',
  guestSubmitAnother: 'Enviar otra idea',
  guestMineHeading: 'Mis ideas en este dispositivo',
  guestMineLead: 'Ideas que enviaste sin cuenta en este navegador.',
  guestStatePending: 'Pendiente de aprobación',
  guestStatePublished: 'Publicada',
  guestStateRejected: 'Rechazada',
  guestRejectedReason: 'Motivo: {reason}',
  guestWithdraw: 'Retirar',
  guestWithdrawFailed: 'No se pudo retirar. Inténtalo de nuevo.',
  guestErrorUrl: 'Sin cuenta no se permiten enlaces. Elimínalos, por favor.',
  guestErrorCaptcha: 'La comprobación anti-bots ha fallado. Inténtalo de nuevo.',
  guestErrorUnavailable: 'Ahora mismo no es posible enviar sin cuenta.',
  guestErrorQueueFull:
    'Ahora mismo hay demasiadas ideas pendientes de aprobación. Inténtalo más tarde.',
  guestErrorRateLimited: 'Demasiados envíos. Espera un momento y vuelve a intentarlo.',
  guestErrorBlocked: 'Tu texto contiene términos no permitidos. Reformúlalo, por favor.',
  guestErrorRejected: 'El envío fue rechazado. Recarga la página e inténtalo de nuevo.',
  tagSuggestionsPlanHint: 'Las sugerencias de etiquetas con IA forman parte de Pro.',
} satisfies typeof de

export default es
