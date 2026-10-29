// machine-drafted, needs native review
import type de from './supportPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar el soporte.',
  title: 'Soporte',
  subtitle:
    'Contacta con nuestro equipo directamente desde el panel: te responderemos lo antes posible.',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody: 'No eres miembro de esta cuenta.',

  formHeading: 'Nueva solicitud',
  categoryLabel: 'Categoría',
  subjectLabel: 'Asunto',
  messageLabel: 'Mensaje',
  submit: 'Enviar',
  submitting: 'Enviando…',
  submitSuccess:
    '¡Gracias! Hemos recibido tu solicitud. Cuando respondamos, encontrarás la respuesta en tu bandeja de entrada.',
  submitFailed: 'No se pudo enviar la solicitud. Inténtalo de nuevo.',

  faqDeflectionHeading: 'Esto quizá ya te ayude',

  ticketsHeading: 'Tus solicitudes ({count})',
  noTickets: 'Aún no has enviado ninguna solicitud de soporte.',
  subjectColumn: 'Asunto',
  categoryColumn: 'Categoría',
  statusColumn: 'Estado',
  updatedColumn: 'Última actividad',
  ticketNotFoundTitle: 'Ticket no encontrado',
  ticketNotFoundBody: 'Esta solicitud de soporte no existe (ya) o no pertenece a esta cuenta.',
  threadHeading: 'Conversación',
  threadLoading: 'Cargando conversación…',
  threadLoadError: 'No se pudo cargar la conversación.',
  fromYou: 'Tú',
  fromSupport: 'Soporte',
  replyLabel: 'Tu respuesta',
  replyPlaceholder: 'Escribe un mensaje…',
  replySubmit: 'Enviar',
  replySubmitting: 'Enviando…',
  replyFailed: 'No se pudo enviar el mensaje. Inténtalo de nuevo.',

  machineTranslatedLabel: 'Traducción automática',
  showOriginalLink: 'Mostrar original',
  showTranslationLink: 'Mostrar traducción',
  translationUnavailableHint: 'La traducción no está disponible en este momento.',

  'category.billing': 'Facturación',
  'category.technical': 'Problema técnico',
  'category.account': 'Cuenta y membresía',
  'category.feature_request': 'Solicitud de función',
  'category.privacy': 'Privacidad',
  'category.other': 'Otro',

  'status.open': 'Abierta',
  'status.answered': 'Respondida',
  'status.closed': 'Cerrada',
} satisfies typeof de

export default es
