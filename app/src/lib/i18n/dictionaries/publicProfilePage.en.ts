import type de from './publicProfilePage.de'

const en = {
  title: 'Profile',
  loading: 'Loading…',
  backToBoard: 'Back to board',
  socialLinksAriaLabel: 'Social links',
  anonymousHint:
    'This member does not show a public profile. On ideas and comments they appear as "Voter".',
  notFoundTitle: 'Member not found',
  notFoundDescription: 'This member does not exist or does not belong to this account.',
  errorTitle: 'Profile could not be loaded',
  loadError: "We couldn't load this profile just now. Please try again.",
  statsIdeasSubmitted: 'Ideas submitted',
  statsIdeasShipped: 'Ideas shipped',
  statsVotesCast: 'Votes cast',
} satisfies typeof de

export default en
