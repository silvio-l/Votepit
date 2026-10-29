// machine-drafted, needs native review
import type de from './appealsAdminPage.de'

const es = {
  loading: 'Cargando…',
  loadError: 'No se pudo cargar la página.',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody:
    'Solo los propietarios, administradores y moderadores de esta cuenta pueden acceder a esta página.',
  resolveFailed: 'No se pudo resolver la apelación. Inténtalo de nuevo.',
  title: 'Apelaciones',
  subtitle: 'Revisa y decide las apelaciones contra decisiones de moderación.',
  appealsHeading: 'Apelaciones ({count} pendientes)',
  emptyState: 'No hay apelaciones.',
  columnContent: 'Contenido eliminado',
  columnReason: 'Motivo',
  columnMessage: 'Mensaje de la apelación',
  columnStatus: 'Estado',
  columnSubmitted: 'Enviada',
  columnActions: 'Acciones',
  statusPending: 'Pendiente',
  statusUpheld: 'Confirmada',
  statusOverturned: 'Revocada',
  overturn: 'Revocar',
  uphold: 'Confirmar',
} satisfies typeof de

export default es
