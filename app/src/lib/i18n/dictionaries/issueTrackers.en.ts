import type de from './issueTrackers.de'

const en = {
  heading: 'Linear & GitHub',
  intro:
    'Connect Linear or GitHub to turn an idea into an issue with one click. When the issue is completed there, the idea moves to "Done" automatically.',
  planUpgradeHint: 'Linear and GitHub issues are not included in your current plan.',
  planUpgradeLinkLabel: 'View plans',
  loadError: 'The connections could not be loaded.',
  providerLinear: 'Linear',
  providerGithub: 'GitHub',
  linearTokenLabel: 'Linear API key',
  linearTokenHint: 'Personal API key from Linear → Settings → Security & access.',
  githubTokenLabel: 'GitHub token',
  githubTokenHint:
    'Fine-grained personal access token with "Issues: Read and write" permission for this repository.',
  tokenKeepHint: 'Leave empty to keep the stored token ({hint}).',
  teamKeyLabel: 'Team key',
  teamKeyHint: 'For example ENG.',
  repositoryLabel: 'Repository',
  repositoryHint: 'As owner/repo.',
  webhookSecretLabel: 'Webhook secret key',
  webhookSecretHint:
    'In Linear (Settings → API → Webhooks), create a webhook for "Issues" with the webhook URL and paste its signing secret here. Leave empty to keep the stored one.',
  connect: 'Connect',
  save: 'Save',
  disconnect: 'Disconnect',
  connectedTo: 'Connected to {target}, token {hint}',
  syncOn: 'Status sync on',
  syncOff: 'Status sync off',
  webhookUrlLabel: 'Webhook URL',
  copy: 'Copy',
  copied: 'Copied',
  githubWebhookSetup:
    'In GitHub (repository → Settings → Webhooks), add a webhook with this URL: content type application/json, the secret below and only the "Issues" event.',
  githubSecretOnceLabel: 'Webhook secret (shown only now)',
  errorInvalidCredentials: 'The token was rejected.',
  errorTargetNotFound: 'Team or repository not found, or the token has no access to it.',
  errorUnavailable: 'The service cannot be reached right now. Please try again later.',
  errorValidation: 'Please check your input.',
  errorGeneric: 'That did not work. Please try again.',
  panelLabel: 'Issue tracker',
  createIssue: 'Create {provider} issue',
  unlink: 'Unlink',
  unlinkAriaLabel: 'Remove the link to {key}',
  errorLinkExists: 'An issue already exists for this idea.',
  errorNotConnected: 'The issue tracker is no longer connected.',
  errorRejected: 'The issue tracker rejected the issue.',
} satisfies typeof de

export default en
