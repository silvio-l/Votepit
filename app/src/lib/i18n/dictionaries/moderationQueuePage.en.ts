import type de from './moderationQueuePage.de'

const en = {
  loading: 'Loading…',
  loadError: 'Page could not be loaded.',
  accessDeniedTitle: 'No access',
  accessDeniedBody:
    'This page is only accessible to owners, admins and moderators of this account.',
  notFoundTitle: 'Board not found',
  notFoundBody: 'This board does not exist in this account.',
  title: 'Approval queue',
  subtitle: 'Review new ideas and approve them before they appear on the board.',
  switchedOffHint:
    'Approval is switched off for this board. New ideas appear immediately; you can still handle the ideas that are waiting here.',
  heading: 'Waiting ideas ({count})',
  emptyState: 'No ideas are waiting for approval.',
  selectAll: 'Select all',
  selectIdea: 'Select “{title}”',
  approveSelected: 'Approve selected ({count})',
  rejectSelected: 'Reject selected ({count})',
  columnSelect: 'Selection',
  columnIdea: 'Idea',
  columnSubmitted: 'Submitted',
  columnActions: 'Actions',
  approve: 'Approve',
  reject: 'Reject',
  approveFailed: 'Approval failed. Please try again.',
  approvedNotice: '{count} idea(s) approved.',
  rejectedNotice: '{count} idea(s) rejected.',
  guestLabel: 'Guest',
  rejectAllGuest: 'Reject all guest ideas ({count})',
  rejectAllGuestTitle: 'Reject {count} guest idea(s)?',
  rejectAllGuestBody:
    'Every waiting idea submitted without an account is rejected as a breach of the board rules. Ideas from members are not affected.',
  rejectAllGuestConfirm: 'Reject all',
  guestRejectedNotice: '{count} guest idea(s) rejected.',
  guestRejectFailed: 'Rejecting didn’t work. Please try again.',
  approveAndTrust: 'Approve and trust author',
  approvedAndTrustedNotice: 'Idea approved. New ideas from this person now appear immediately.',
  approveAndTrustFailed: 'Approving and trusting didn’t work. Please try again.',
  trustedAuthorsLink: 'Trusted authors',
} satisfies typeof de

export default en
