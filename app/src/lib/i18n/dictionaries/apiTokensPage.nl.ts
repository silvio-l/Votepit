// machine-drafted, needs native review
import type de from './apiTokensPage.de'

const nl = {
  loading: 'Laden…',
  accessDeniedTitle: 'Geen toegang',
  accessDeniedBody: 'Deze pagina is alleen toegankelijk voor boardbeheerders.',
  loadError: 'De pagina kon niet worden geladen.',
  title: 'API-tokens',
  subtitle:
    'Toegangssleutels voor bots en AI-agents — geef ze toegang tot een of meer van je boards.',
  createFailed: 'Aanmaken mislukt. Probeer het opnieuw.',
  revokeFailed: 'Intrekken mislukt.',
  confirmRevoke:
    'Dit API-token echt intrekken? Elke integratie die het gebruikt, stopt direct en definitief met werken.',
  createHeading: 'Nieuw token aanmaken',
  createBody:
    'Het token wordt maar één keer getoond, direct na het aanmaken. Kopieer het meteen; daarna is het niet meer zichtbaar.',
  labelField: 'Label',
  labelPlaceholder: 'bijv. CI-bot',
  scopeField: 'Toegang',
  scopeNone: 'Geen toegang',
  scopeRead: 'Alleen lezen',
  scopeWrite: 'Lezen & schrijven',
  scopeModerate: 'Lezen, schrijven & modereren',
  scopeModerateHint:
    '‘Modereren’ laat het token daarnaast status, tags en samenvoegingen wijzigen en ideeën lezen die op goedkeuring wachten – alleen zolang jij dit account modereert.',
  scopeManage: 'Beheren (branding & afbeeldingen)',
  scopeManageHint:
    '‘Beheren’ laat het token daarnaast de branding van het board wijzigen en afbeeldingen van het board uploaden of verwijderen – alleen zolang de persoon die het token heeft aangemaakt nog eigenaar of beheerder van het account is. De lagere rechten omvatten dit nooit.',
  boardsField: 'Boards',
  boardScopeAriaLabel: 'Toegang voor {board}',
  noBoardsYet: 'Nog geen boards — maak eerst een board aan.',
  creating: 'Aanmaken…',
  createSubmit: 'Token aanmaken',
  revealedTokenNotice: 'Token "{label}" — kopieer het nu, het wordt niet nog een keer getoond:',
  revealedTokenFieldLabel: 'Token',
  tokensHeading: 'Tokens ({count})',
  tokensAriaLabel: 'API-tokens',
  noTokensYet: 'Nog geen tokens aangemaakt.',
  createdOn: 'aangemaakt op {date}',
  lastUsedOn: ', laatst gebruikt op {date}',
  revokedSuffix: 'ingetrokken',
  oauthBadge: 'OAuth',
  revokeAriaLabel: 'Token "{label}" intrekken',
  revoke: 'Intrekken',
  docsIntro: 'Meer informatie:',
  docsApiReference: 'API-referentie',
  docsMcpServer: 'MCP-server',
  setupHeading: 'Een agent instellen',
  setupIntro:
    'Kies hieronder een doel om een kant-en-klare prompt voor een AI-codeeragent te krijgen — die bevat al dit token en je board-URL, zodat de agent zichzelf kan configureren en de verbinding kan controleren.',
  setupTargetLabel: 'Doel',
  setupTargetClaudeCode: 'Claude Code (MCP)',
  setupTargetClaudeCodeCli: 'Claude Code (CLI + skill, geen MCP)',
  setupTargetClaudeDesktop: 'Claude Desktop',
  setupTargetCursorVscode: 'Cursor / VS Code',
  setupTargetGenericCli: 'Generieke CLI (@votepit/cli)',
  setupPromptFieldLabel: 'Prompt voor je AI-agent',
  planLimitAgentApi: 'De Agent-API zit niet in je huidige abonnement.',
} satisfies typeof de

export default nl
