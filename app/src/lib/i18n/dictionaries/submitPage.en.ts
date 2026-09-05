import type de from './submitPage.de'

const en = {
  loading: 'Loading…',
  loadError: "The form couldn't be loaded just now. Please try again.",
  backToList: 'Back to board',
  heading: 'Submit a new idea',
  subtitle: 'A clear title and a short paragraph — plain text, no formatting.',
  honeypotLabel: 'Website',
  titleLabel: 'Title',
  titlePlaceholder: 'Short, concise title',
  titleHint: '3–200 characters',
  duplicateHintQuestion: 'Does this idea already exist?',
  duplicateHintLead: 'Backing an existing idea carries more weight than adding a second one.',
  votesCount: '{count} votes',
  submitAnywayButton: 'Submit as a new idea anyway',
  bodyLabel: 'Description',
  bodyPlaceholder: 'What is it about? What should change?',
  bodyHint: 'At least 10 characters',
  submitting: 'Submitting…',
  submit: 'Submit idea',
} satisfies typeof de

export default en
