// machine-drafted, needs native review
import type de from './trustedAuthorsPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar la página.',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody:
    'Esta página solo es accesible para propietarios, administradores y moderadores de esta cuenta.',
  title: 'Autores de confianza',
  subtitle:
    'Las ideas de estas personas aparecen de inmediato en los tableros con aprobación, sin pasar por la cola. Se aplica a todos los tableros de esta cuenta.',
  heading: 'De confianza ({count})',
  emptyState:
    'Todavía nadie está marcado como de confianza. Usa «Aprobar y confiar en el autor» en la cola de aprobación.',
  columnAuthor: 'Autor',
  columnTrustedSince: 'De confianza desde',
  columnActions: 'Acciones',
  userLabel: 'Usuario #{id}',
  userLabelWithUsername: 'Usuario #{id} ({username})',
  revoke: 'Retirar confianza',
  revokeAriaLabel: 'Retirar la confianza al usuario #{id}',
  revokeTitle: '¿Retirar la confianza?',
  revokeBody:
    'Las nuevas ideas de esta persona volverán a esperar aprobación. Las ideas ya publicadas siguen visibles.',
  revokeConfirm: 'Retirar',
  revokedNotice: 'Confianza retirada.',
  revokeFailed: 'No se pudo retirar. Inténtalo de nuevo.',
  autoRevokeHint:
    'Si una persona recibe una advertencia o una suspensión, la confianza termina automáticamente.',
} satisfies typeof de

export default es
