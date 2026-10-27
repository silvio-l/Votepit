import type de from './appealsAdminPage.de'

const en = {
  loading: 'Loading…',
  loadError: 'Page could not be loaded.',
  accessDeniedTitle: 'No access',
  accessDeniedBody:
    'This page is only accessible to owners, admins and moderators of this account.',
  resolveFailed: 'The appeal could not be resolved. Please try again.',
  title: 'Appeals',
  subtitle: 'Review and decide appeals against moderation decisions.',
  appealsHeading: 'Appeals ({count} pending)',
  emptyState: 'There are no appeals.',
  columnContent: 'Removed content',
  columnReason: 'Reason',
  columnMessage: 'Appeal message',
  columnStatus: 'Status',
  columnSubmitted: 'Submitted',
  columnActions: 'Actions',
  statusPending: 'Pending',
  statusUpheld: 'Upheld',
  statusOverturned: 'Overturned',
  overturn: 'Overturn',
  uphold: 'Uphold',
} satisfies typeof de

export default en
