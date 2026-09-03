import type de from './accountPage.de'

const en = {
  loading: 'Loading…',
  accessDeniedTitle: 'No access',
  accessDeniedBody: 'This page is only accessible to the account owner.',
  loadError: 'Page could not be loaded.',
  title: 'Account',
  subtitle: 'Data export and deletion for this account.',
  accountHeading: 'This account',
  nameLabel: 'Name',
  slugLabel: 'Slug',
  deletionScheduledHeading: 'Account is being deleted',
  deletionScheduledPart1:
    'This account, including all boards, ideas and comments, will be permanently deleted on',
  deletionScheduledPart2Days: '(',
  deletionScheduledPart3Days: 'days remaining).',
  deletionScheduledPart2Hours: '(',
  deletionScheduledPart3Hours: 'hours remaining).',
  deletionScheduledHint: 'While the grace period is running you can cancel the deletion here.',
  deletionScheduledUndo: 'Cancel deletion',
  deletionScheduledUndoing: 'Cancelling…',
  deletionScheduledUndoFailed: 'Could not cancel the deletion.',
  exportHeading: 'Export my data',
  exportBody:
    'Download a complete copy of all this account’s data (boards, ideas, votes, comments, members, invitations and more) — as a JSON document or as a ZIP archive with one CSV file per table.',
  exportFailed: 'Export could not be downloaded.',
  exportingJson: 'Exporting…',
  exportJson: 'Export as JSON',
  exportingCsv: 'Exporting…',
  exportCsv: 'Export as CSV (ZIP)',
  dangerZoneHeading: 'Danger zone',
  dangerZoneBody:
    'Permanently deletes this account — all boards, ideas, votes, comments and memberships. The global login (email account) stays intact, only this account disappears. Once requested, you have 48 hours to cancel the deletion here.',
  deleteAccountButton: 'Delete account',
  deleteAccountConfirmLabel: 'Type "{slug}" to confirm',
  deleteAccountConfirmPlaceholder: 'Account slug',
  deleteAccountConfirmSubmit: 'Delete permanently',
  deleteAccountConfirmCancel: 'Cancel',
  deleteAccountFailed: 'Could not request deletion.',
  deletingAccount: 'Requesting…',
} satisfies typeof de

export default en
