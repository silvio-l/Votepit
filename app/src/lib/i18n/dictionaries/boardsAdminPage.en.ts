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
  manage: 'Settings',
  view: 'View',
  createHeading: 'Create a new board',
  nameLabel: 'Name',
  namePlaceholder: 'e.g. Product feedback',
  slugLabel: 'Slug',
  slugPlaceholder: 'e.g. product-feedback',
  slugHint: 'Suggested from the name, but freely editable.',
  createSubmitting: 'Creating…',
  createSubmit: 'Create board',
  newBoard: 'New board',
  statBoards: 'Boards',
  statIdeas: 'Ideas',
  statVotes: 'Votes',
  statCaptionAll: 'across all boards',
  ideasCount: '{count} ideas',
  votesCount: '{count} votes',
  createHint: 'A board is a public page with its own link where people submit ideas and vote.',
  emptyDescription:
    'Create a board and share its link — ideas and votes collect there on their own.',
} satisfies typeof de

export default en
