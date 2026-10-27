// machine-drafted, needs native review
import type de from './issueTrackers.de'

const es = {
  heading: 'Linear y GitHub',
  intro:
    'Conecta Linear o GitHub para convertir una idea en una incidencia con un clic. Cuando la incidencia se completa allí, la idea pasa automáticamente a «Hecho».',
  planUpgradeHint: 'Las incidencias de Linear y GitHub no están incluidas en tu plan actual.',
  planUpgradeLinkLabel: 'Ver planes',
  loadError: 'No se pudieron cargar las conexiones.',
  providerLinear: 'Linear',
  providerGithub: 'GitHub',
  linearTokenLabel: 'Clave API de Linear',
  linearTokenHint: 'Clave API personal de Linear → Settings → Security & access.',
  githubTokenLabel: 'Token de GitHub',
  githubTokenHint:
    'Token de acceso personal «fine-grained» con el permiso «Issues: Read and write» para este repositorio.',
  tokenKeepHint: 'Déjalo vacío para conservar el token guardado ({hint}).',
  teamKeyLabel: 'Clave del equipo',
  teamKeyHint: 'Por ejemplo ENG.',
  repositoryLabel: 'Repositorio',
  repositoryHint: 'Con el formato owner/repo.',
  webhookSecretLabel: 'Clave secreta del webhook',
  webhookSecretHint:
    'En Linear (Settings → API → Webhooks), crea un webhook para «Issues» con la URL del webhook y pega aquí su secreto de firma. Déjalo vacío para conservar el guardado.',
  connect: 'Conectar',
  save: 'Guardar',
  disconnect: 'Desconectar',
  connectedTo: 'Conectado a {target}, token {hint}',
  syncOn: 'Sincronización de estado activa',
  syncOff: 'Sincronización de estado desactivada',
  webhookUrlLabel: 'URL del webhook',
  copy: 'Copiar',
  copied: 'Copiado',
  githubWebhookSetup:
    'En GitHub (repositorio → Settings → Webhooks), añade un webhook con esta URL: content type application/json, el secreto de abajo y solo el evento «Issues».',
  githubSecretOnceLabel: 'Secreto del webhook (solo se muestra ahora)',
  errorInvalidCredentials: 'El token fue rechazado.',
  errorTargetNotFound: 'Equipo o repositorio no encontrado, o el token no tiene acceso.',
  errorUnavailable: 'El servicio no está disponible ahora mismo. Inténtalo más tarde.',
  errorValidation: 'Revisa los datos introducidos.',
  errorGeneric: 'No ha funcionado. Inténtalo de nuevo.',
  panelLabel: 'Gestor de incidencias',
  createIssue: 'Crear incidencia en {provider}',
  unlink: 'Desvincular',
  unlinkAriaLabel: 'Quitar el vínculo con {key}',
  errorLinkExists: 'Ya existe una incidencia para esta idea.',
  errorNotConnected: 'El gestor de incidencias ya no está conectado.',
  errorRejected: 'El gestor de incidencias rechazó la incidencia.',
} satisfies typeof de

export default es
