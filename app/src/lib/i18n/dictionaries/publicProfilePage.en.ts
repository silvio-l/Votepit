import type de from './publicProfilePage.de'

const en = {
  title: 'Profile',
  loading: 'Loading…',
  backToBoard: 'Back to board',
  socialLinksAriaLabel: 'Social links',
  anonymousHint:
    'This member does not show a public profile. On ideas and comments they appear as "Voter".',
  notFoundTitle: "This member isn't here right now",
  notFoundDescription:
    'The profile may no longer be visible, or it may no longer exist. The homepage is a good place to carry on.',
  errorTitle: 'Profile could not be loaded',
  loadError: "We couldn't load this profile just now. Please try again.",
  statsIdeasSubmitted: 'Ideas submitted',
  statsIdeasShipped: 'Ideas completed',
  statsVotesCast: 'Votes cast',
} satisfies typeof de

export default en
