// machine-drafted, needs native review
import type de from './moderationQueuePage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar la página.',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody:
    'Esta página solo está disponible para propietarios, administradores y moderadores de esta cuenta.',
  notFoundTitle: 'Tablero no encontrado',
  notFoundBody: 'Este tablero no existe en esta cuenta.',
  title: 'Cola de aprobación',
  subtitle: 'Revisa las ideas nuevas y apruébalas antes de que aparezcan en el tablero.',
  switchedOffHint:
    'La aprobación está desactivada en este tablero. Las ideas nuevas aparecen de inmediato; aún puedes gestionar las ideas que esperan aquí.',
  heading: 'Ideas en espera ({count})',
  emptyState: 'No hay ideas pendientes de aprobación.',
  selectAll: 'Seleccionar todo',
  selectIdea: 'Seleccionar «{title}»',
  approveSelected: 'Aprobar selección ({count})',
  rejectSelected: 'Rechazar selección ({count})',
  columnSelect: 'Selección',
  columnIdea: 'Idea',
  columnSubmitted: 'Enviada',
  columnActions: 'Acciones',
  approve: 'Aprobar',
  reject: 'Rechazar',
  approveFailed: 'No se pudo aprobar. Inténtalo de nuevo.',
  approvedNotice: '{count} idea(s) aprobada(s).',
  rejectedNotice: '{count} idea(s) rechazada(s).',
  guestLabel: 'Invitado',
  rejectAllGuest: 'Rechazar todas las ideas de invitados ({count})',
  rejectAllGuestTitle: '¿Rechazar {count} idea(s) de invitados?',
  rejectAllGuestBody:
    'Todas las ideas pendientes enviadas sin cuenta se rechazan por infringir las normas del tablero. Las ideas de los miembros no se ven afectadas.',
  rejectAllGuestConfirm: 'Rechazar todas',
  guestRejectedNotice: '{count} idea(s) de invitados rechazada(s).',
  guestRejectFailed: 'No se pudo rechazar. Inténtalo de nuevo.',
  approveAndTrust: 'Aprobar y confiar en el autor',
  approvedAndTrustedNotice:
    'Idea aprobada. Las nuevas ideas de esta persona aparecerán ahora de inmediato.',
  approveAndTrustFailed: 'No se pudo aprobar y confiar. Inténtalo de nuevo.',
  trustedAuthorsLink: 'Autores de confianza',
} satisfies typeof de

export default es
