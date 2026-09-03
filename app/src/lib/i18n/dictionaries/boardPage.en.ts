import type de from './boardPage.de'

const en = {
  timeAgoJustNow: 'just now',
  timeAgoMinutes: '{count} min ago',
  timeAgoHours: '{count} h ago',
  timeAgoDaySingular: '{count} day ago',
  timeAgoDayPlural: '{count} days ago',
  timeAgoMonthSingular: '{count} month ago',
  timeAgoMonthPlural: '{count} months ago',
  timeAgoYearSingular: '{count} year ago',
  timeAgoYearPlural: '{count} years ago',
  noBoardTitle: 'No board set up yet',
  noBoardConfigured: 'Once the first board exists, its ideas and their votes show up here.',
  loadError: "We couldn't load the ideas just now. Please try again.",
  boardNotFound: "This board doesn't exist — it may have been renamed or removed.",
  backHome: 'Go to homepage',
  loading: 'Loading…',
  boardNotFoundTitle: 'Board not found',
  loadErrorTitle: 'Ideas could not be loaded',
  newIdea: 'New idea',
  noIdeasForStatusTitle: 'No ideas with this status',
  noIdeasForStatusDescription:
    'Nothing matches this filter right now. Pick a different status, or show all ideas.',
  resetFilter: 'Show all ideas',
  noIdeasTitle: 'No ideas yet',
  noIdeasDescription:
    'Nothing has been submitted here yet. Go first — the first idea gets the board going.',
  submitFirstIdea: 'Submit first idea',
  ideasAriaLabel: 'Ideas',
  poweredBy: 'Powered by Votepit',
} satisfies typeof de

export default en
