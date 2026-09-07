import type de from './roadmapPage.de'

const en = {
  viewAriaLabel: 'View',
  viewList: 'List',
  viewColumns: 'Columns',
  ideaSingular: 'idea',
  ideaPlural: 'ideas',
  ideasAriaLabel: 'Roadmap ideas',
  sectionOpen: 'Open',
  sectionPlanned: 'Planned',
  sectionInProgress: 'In progress',
  sectionDone: 'Done',
  sectionDeclined: 'Declined',
  loading: 'Loading…',
  errorNotFound: "This board doesn't exist — it may have been renamed or removed.",
  errorLoadFailed: "We couldn't load the roadmap just now. Please try again.",
  notFoundTitle: 'Board not found',
  backHome: 'Go to homepage',
  errorHeading: 'Error loading data',
  retry: 'Try again',
  heading: 'Roadmap',
  subtitle: '{name} — planned, in-progress and completed features at a glance.',
  emptyPlannedTitle: 'Nothing planned yet',
  emptyPlannedDescription: 'Ideas that make it onto the roadmap show up here.',
  emptyInProgressTitle: 'Nothing in progress',
  emptyInProgressDescription: 'As soon as an idea is being worked on, it moves here.',
  emptyDoneTitle: 'Nothing shipped yet',
  emptyDoneDescription: 'Ideas that have been built collect here.',
} satisfies typeof de

export default en
