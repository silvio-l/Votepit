import type de from './discoverPage.de'

const en = {
  heading: 'Where votes move things',
  subtitle:
    'Public Votepit boards where real communities vote on real ideas. Browse, cast your vote, and see what people actually want.',
  loading: 'Loading…',
  errorHeading: 'Failed to load',
  errorLoadFailed: 'We could not load the public boards right now. Please try again.',
  retry: 'Retry',
  emptyTitle: 'No public boards yet',
  emptyDescription: 'Once a board is set to "Public", it shows up here.',
  boardsAriaLabel: 'Public boards',
  votesCount: '{count} votes',
  ideasCount: '{count} ideas',
  paginationAriaLabel: 'Pages',
  paginationPageOf: 'Page {page} of {totalPages}',
  paginationPrev: 'Previous',
  paginationNext: 'Next',
} satisfies typeof de
export default en
