// machine-drafted, needs native review
import type de from './apiTokensPage.de'

const it = {
  loading: 'Caricamento…',
  accessDeniedTitle: 'Nessun accesso',
  accessDeniedBody: 'Questa pagina è accessibile solo agli amministratori della bacheca.',
  loadError: 'Impossibile caricare la pagina.',
  title: 'Token API',
  subtitle:
    'Chiavi di accesso per bot e agenti IA: concedi l’accesso a una o più delle tue bacheche.',
  createFailed: 'Creazione non riuscita. Riprova.',
  revokeFailed: 'Revoca non riuscita.',
  confirmRevoke:
    'Vuoi davvero revocare questo token API? Qualsiasi integrazione che lo usa smetterà di funzionare immediatamente e definitivamente.',
  createHeading: 'Crea nuovo token',
  createBody:
    'Il token viene mostrato una sola volta, subito dopo la creazione. Copialo subito: in seguito non sarà più visibile.',
  labelField: 'Etichetta',
  labelPlaceholder: 'es. Bot CI',
  scopeField: 'Accesso',
  scopeNone: 'Nessun accesso',
  scopeRead: 'Sola lettura',
  scopeWrite: 'Lettura e scrittura',
  scopeModerate: 'Lettura, scrittura e moderazione',
  scopeModerateHint:
    '«Moderazione» consente inoltre al token di modificare stato, tag e unioni e di leggere le idee in attesa di approvazione, solo finché moderi questo account.',
  boardsField: 'Bacheche',
  boardScopeAriaLabel: 'Accesso per {board}',
  noBoardsYet: 'Ancora nessuna bacheca — crea prima una bacheca.',
  creating: 'Creazione…',
  createSubmit: 'Crea token',
  revealedTokenNotice: 'Token «{label}» — copialo ora, non verrà mostrato di nuovo:',
  revealedTokenFieldLabel: 'Token',
  tokensHeading: 'Token ({count})',
  tokensAriaLabel: 'Token API',
  noTokensYet: 'Ancora nessun token creato.',
  createdOn: 'creato il {date}',
  lastUsedOn: ', ultimo utilizzo il {date}',
  revokedSuffix: 'revocato',
  oauthBadge: 'OAuth',
  revokeAriaLabel: 'Revoca il token «{label}»',
  revoke: 'Revoca',
  docsIntro: 'Scopri di più:',
  docsApiReference: 'Riferimento API',
  docsMcpServer: 'Server MCP',
  setupHeading: 'Configura un agente',
  setupIntro:
    'Scegli una destinazione qui sotto per ottenere un prompt pronto da incollare per un agente di programmazione IA — contiene già questo token e l’URL della tua bacheca, così l’agente può configurarsi da solo e verificare la connessione.',
  setupTargetLabel: 'Destinazione',
  setupTargetClaudeCode: 'Claude Code (MCP)',
  setupTargetClaudeCodeCli: 'Claude Code (CLI + skill, senza MCP)',
  setupTargetClaudeDesktop: 'Claude Desktop',
  setupTargetCursorVscode: 'Cursor / VS Code',
  setupTargetGenericCli: 'CLI generica (@votepit/cli)',
  setupPromptFieldLabel: 'Prompt per il tuo agente IA',
  planLimitAgentApi: "L'API per agenti non è inclusa nel tuo piano attuale.",
} satisfies typeof de

export default it
