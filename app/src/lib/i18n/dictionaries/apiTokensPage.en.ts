import type de from './apiTokensPage.de'

const en = {
  loading: 'Loading…',
  accessDeniedTitle: 'No access',
  accessDeniedBody: 'This page is only accessible to board administrators.',
  loadError: 'Page could not be loaded.',
  title: 'API tokens',
  subtitle:
    'Bearer tokens for bot/agent integrations — grant access to one or more of your boards.',
  createFailed: 'Creation failed. Please try again.',
  revokeFailed: 'Revoke failed.',
  confirmRevoke:
    'Really revoke this API token? Any integration using it stops working immediately and permanently.',
  createHeading: 'Create new token',
  createBody:
    'The token is shown in plaintext exactly once after creation — it can no longer be retrieved afterwards.',
  labelField: 'Label',
  labelPlaceholder: 'e.g. CI bot',
  scopeField: 'Access',
  scopeNone: 'No access',
  scopeRead: 'Read-only',
  scopeWrite: 'Read & write',
  boardsField: 'Boards',
  boardScopeAriaLabel: 'Access for {board}',
  noBoardsYet: 'No boards yet — create a board first.',
  creating: 'Creating…',
  createSubmit: 'Create token',
  revealedTokenNotice: 'Token „{label}" — copy it now, it won\'t be shown again:',
  revealedTokenFieldLabel: 'Token',
  tokensHeading: 'Tokens ({count})',
  tokensAriaLabel: 'API tokens',
  noTokensYet: 'No tokens created yet.',
  createdOn: 'created on {date}',
  lastUsedOn: ', last used on {date}',
  revokedSuffix: 'revoked',
  revokeAriaLabel: 'Revoke token „{label}"',
  revoke: 'Revoke',
  docsIntro: 'Learn more:',
  docsApiReference: 'API reference',
  docsMcpServer: 'MCP server',
  setupHeading: 'Set up an agent',
  setupIntro:
    'Pick a target below to get a ready-to-paste prompt for an AI coding agent — it already contains this token and your board URL, so the agent can configure itself and verify the connection.',
  setupTargetLabel: 'Target',
  setupTargetClaudeCode: 'Claude Code (MCP)',
  setupTargetClaudeCodeCli: 'Claude Code (CLI + skill, no MCP)',
  setupTargetClaudeDesktop: 'Claude Desktop',
  setupTargetCursorVscode: 'Cursor / VS Code',
  setupTargetGenericCli: 'Generic CLI (@votepit/cli)',
  setupPromptFieldLabel: 'Prompt for your AI agent',
} satisfies typeof de

export default en
