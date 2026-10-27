import type de from './boardsAdminPage.de'

const en = {
  loading: 'Loading…',
  accessDeniedTitle: 'Access denied',
  accessDeniedBody: 'This page is only accessible to account administrators.',
  loadError: 'The page could not be loaded.',
  title: 'Boards',
  subtitle: 'All boards in your account.',
  noBoards: 'No boards created yet.',
  createFirstBoard: 'Create your first one now',
  boardsAriaLabel: 'Boards',
  boardsHeading: 'Boards ({count})',
  frozen: 'Frozen',
  complianceFrozenAccount: 'Hidden — complete operator details',
  complianceFrozenHintAccount:
    'This board is hidden because the account-wide legal operator identity (name, address & contact email) is incomplete. Click to complete it on the Account page — this unfreezes every board that already has its own operator acknowledgment confirmed.',
  complianceFrozenBoard: 'Hidden — confirm operator notice',
  complianceFrozenHintBoard:
    'The account-wide legal operator name is set, but this board still needs its own operator-responsibility notice confirmed. Click to confirm it here.',
  manage: 'Settings',
  view: 'View',
  copyLink: 'Copy board link',
  copyLinkCopied: 'Link copied!',
  createHeading: 'Create a new board',
  nameLabel: 'Name',
  namePlaceholder: 'e.g. Our ideas',
  slugLabel: 'Slug',
  slugPlaceholder: 'e.g. our-ideas',
  slugHint: 'Suggested from the name, but freely editable.',
  createSubmitting: 'Creating…',
  createSubmit: 'Create board',
  newBoard: 'New board',
  statBoards: 'Boards',
  statIdeas: 'Ideas',
  statVotes: 'Votes',
  statCaptionAll: 'across all boards',
  ideasCount: '{count} ideas',
  ideasCountSingular: '{count} idea',
  votesCount: '{count} votes',
  votesCountSingular: '{count} vote',
  createHint: 'A board is a public page with its own link where people submit ideas and vote.',
  emptyDescription:
    'Create a board and share its link — ideas and votes collect there on their own.',
  operatorAckLabel:
    'I confirm that I am the legal operator of this board — responsible for its community rules, moderation, and any board-level Impressum/privacy obligations.',
} satisfies typeof de

export default en
