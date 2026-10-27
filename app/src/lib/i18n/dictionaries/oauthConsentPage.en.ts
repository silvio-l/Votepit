import type de from './oauthConsentPage.de'

const en = {
  loading: 'Checking the request…',
  redirecting: 'Returning to the application…',
  errorTitle: 'Invalid request',
  invalidRequest:
    'This authorization request is invalid or has expired. Start the connection again in your application.',
  title: '{client} wants to access Votepit',
  intro:
    'The application gets access to the boards you select here, each with the permission you choose. It acts on your behalf through the Agent API.',
  redirectNotice: 'After you decide, you will be sent back to:',
  accountLabel: 'Account',
  accountUnavailable: '{account} (Agent API not included in the current plan)',
  boardsLegend: 'Boards and permission',
  levelLabel: 'Permission for {board}',
  'level.read': 'Read',
  'level.write': 'Read & write',
  'level.moderate': 'Moderate',
  noBoards: 'This account has no boards yet.',
  noAccounts:
    'You cannot grant access to any account — you need to be an owner or admin of an account.',
  planLimit: "The Agent API is not included in this account's current plan.",
  submitFailed: 'Your decision could not be saved. Please try again.',
  approve: 'Allow access',
  deny: 'Deny',
  revokeHint: 'You can revoke access at any time under Admin → API tokens.',
} satisfies typeof de

export default en
