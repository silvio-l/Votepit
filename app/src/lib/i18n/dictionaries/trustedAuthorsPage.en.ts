import type de from './trustedAuthorsPage.de'

const en = {
  loading: 'Loading…',
  loadError: 'Page could not be loaded.',
  accessDeniedTitle: 'No access',
  accessDeniedBody:
    'This page is only accessible to owners, admins and moderators of this account.',
  title: 'Trusted authors',
  subtitle:
    'Ideas from these people appear immediately on boards that require approval, without waiting in the queue. This applies to every board of this account.',
  heading: 'Trusted ({count})',
  emptyState: 'Nobody is trusted yet. Use “Approve and trust author” in the approval queue.',
  columnAuthor: 'Author',
  columnTrustedSince: 'Trusted since',
  columnActions: 'Actions',
  userLabel: 'Person #{id}',
  userLabelWithUsername: 'Person #{id} ({username})',
  revoke: 'Revoke trust',
  revokeAriaLabel: 'Revoke trust for person #{id}',
  revokeTitle: 'Revoke trust?',
  revokeBody:
    'New ideas from this person will wait for approval again. Ideas already published stay visible.',
  revokeConfirm: 'Revoke',
  revokedNotice: 'Trust revoked.',
  revokeFailed: 'Revoking didn’t work. Please try again.',
  autoRevokeHint: 'If a person is warned or suspended, their trust ends automatically.',
} satisfies typeof de

export default en
