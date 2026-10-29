// machine-drafted, needs native review
import type de from './oauthConsentPage.de'

const es = {
  loading: 'Comprobando la solicitud…',
  redirecting: 'Volviendo a la aplicación…',
  errorTitle: 'Solicitud no válida',
  invalidRequest:
    'Esta solicitud de autorización no es válida o ha caducado. Vuelve a iniciar la conexión en tu aplicación.',
  title: '{client} quiere acceder a Votepit',
  intro:
    'La aplicación obtiene acceso a los tableros que selecciones aquí, cada uno con el permiso que elijas. Actúa en tu nombre a través de la API de agentes.',
  redirectNotice: 'Tras tu decisión volverás a:',
  accountLabel: 'Cuenta',
  accountUnavailable: '{account} (API de agentes no incluida en el plan actual)',
  boardsLegend: 'Tableros y permiso',
  levelLabel: 'Permiso para {board}',
  'level.read': 'Lectura',
  'level.write': 'Lectura y escritura',
  'level.moderate': 'Moderación',
  noBoards: 'Esta cuenta aún no tiene tableros.',
  noAccounts:
    'No puedes conceder acceso a ninguna cuenta: necesitas ser propietario o administrador de una cuenta.',
  planLimit: 'La API de agentes no está incluida en el plan actual de esta cuenta.',
  submitFailed: 'No se pudo guardar tu decisión. Inténtalo de nuevo.',
  approve: 'Permitir acceso',
  deny: 'Rechazar',
  revokeHint: 'Puedes revocar el acceso en cualquier momento en Admin → Tokens de API.',
} satisfies typeof de

export default es
