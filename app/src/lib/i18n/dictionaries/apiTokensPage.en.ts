import type de from './apiTokensPage.de'

const en = {
  loading: 'Loading…',
  accessDeniedTitle: 'No access',
  accessDeniedBody: 'This page is only accessible to board administrators.',
  loadError: 'Page could not be loaded.',
  title: 'API tokens',
  subtitle:
    'Bearer tokens for bot/agent integrations — each token only ever applies to this board.',
  createFailed: 'Creation failed. Please try again.',
  revokeFailed: 'Revoke failed.',
  confirmRevoke:
    'Really revoke this API token? Any integration using it stops working immediately and permanently.',
  createHeading: 'Create new token',
  createBody:
    'The token is shown in plaintext exactly once after creation — it can no longer be retrieved afterwards.',
  labelField: 'Label',
  labelPlaceholder: 'e.g. CI bot',
  creating: 'Creating…',
  createSubmit: 'Create token',
  revealedTokenNotice: 'Token „{label}" — copy it now, it won\'t be shown again:',
  tokensHeading: 'Tokens ({count})',
  tokensAriaLabel: 'API tokens',
  noTokensYet: 'No tokens created yet.',
  createdOn: 'created on {date}',
  lastUsedOn: ', last used on {date}',
  revokedSuffix: 'revoked',
  revokeAriaLabel: 'Revoke token „{label}"',
  revoke: 'Revoke',
} satisfies typeof de

export default en
