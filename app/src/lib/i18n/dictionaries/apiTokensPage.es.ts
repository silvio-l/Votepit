// machine-drafted, needs native review
import type de from './apiTokensPage.de'

const es = {
  loading: 'Cargando…',
  accessDeniedTitle: 'Sin acceso',
  accessDeniedBody: 'Solo los administradores del tablero pueden acceder a esta página.',
  loadError: 'No se pudo cargar la página.',
  title: 'Tokens de API',
  subtitle:
    'Claves de acceso para bots y agentes de IA: concédeles acceso a uno o varios de tus tableros.',
  createFailed: 'No se pudo crear. Inténtalo de nuevo.',
  revokeFailed: 'No se pudo revocar.',
  confirmRevoke:
    '¿Seguro que quieres revocar este token de API? Cualquier integración que lo use dejará de funcionar de inmediato y de forma permanente.',
  createHeading: 'Crear nuevo token',
  createBody:
    'El token solo se muestra una vez, justo después de crearlo. Cópialo en ese momento: después ya no se podrá volver a ver.',
  labelField: 'Etiqueta',
  labelPlaceholder: 'p. ej. bot de CI',
  scopeField: 'Acceso',
  scopeNone: 'Sin acceso',
  scopeRead: 'Solo lectura',
  scopeWrite: 'Lectura y escritura',
  scopeModerate: 'Lectura, escritura y moderación',
  scopeModerateHint:
    '«Moderación» permite además al token cambiar estado, etiquetas y fusiones y leer ideas pendientes de aprobación, solo mientras sigas moderando esta cuenta.',
  boardsField: 'Tableros',
  boardScopeAriaLabel: 'Acceso para {board}',
  noBoardsYet: 'Aún no hay tableros; crea primero un tablero.',
  creating: 'Creando…',
  createSubmit: 'Crear token',
  revealedTokenNotice: 'Token «{label}»: cópialo ahora, no se volverá a mostrar:',
  revealedTokenFieldLabel: 'Token',
  tokensHeading: 'Tokens ({count})',
  tokensAriaLabel: 'Tokens de API',
  noTokensYet: 'Aún no se han creado tokens.',
  createdOn: 'creado el {date}',
  lastUsedOn: ', usado por última vez el {date}',
  revokedSuffix: 'revocado',
  oauthBadge: 'OAuth',
  revokeAriaLabel: 'Revocar el token «{label}»',
  revoke: 'Revocar',
  docsIntro: 'Más información:',
  docsApiReference: 'Referencia de la API',
  docsMcpServer: 'Servidor MCP',
  setupHeading: 'Configurar un agente',
  setupIntro:
    'Elige un destino abajo para obtener un prompt listo para pegar en un agente de programación con IA; ya contiene este token y la URL de tu tablero, para que el agente pueda configurarse solo y verificar la conexión.',
  setupTargetLabel: 'Destino',
  setupTargetClaudeCode: 'Claude Code (MCP)',
  setupTargetClaudeCodeCli: 'Claude Code (CLI + skill, sin MCP)',
  setupTargetClaudeDesktop: 'Claude Desktop',
  setupTargetCursorVscode: 'Cursor / VS Code',
  setupTargetGenericCli: 'CLI genérica (@votepit/cli)',
  setupPromptFieldLabel: 'Prompt para tu agente de IA',
  planLimitAgentApi: 'La API para agentes no está incluida en tu plan actual.',
} satisfies typeof de

export default es
