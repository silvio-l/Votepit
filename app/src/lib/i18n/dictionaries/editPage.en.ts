import type de from './editPage.de'

const en = {
  forbiddenError: 'Only the person who submitted an idea can edit it.',
  notFoundError: 'This idea is gone — it may have been withdrawn.',
  editWindowExpiredTitle: 'Edit window expired',
  editWindowExpiredError:
    'Ideas can only be edited within two hours of being submitted. That window has closed.',
  loadError: "The form couldn't be loaded just now. Please try again.",
  genericError: 'Something went wrong. Please try again.',
  loading: 'Loading…',
  backToIdea: 'Back to idea',
  backToBoard: 'Back to board',
  heading: 'Edit idea',
  subheading: 'A clear title and a short paragraph — plain text, no formatting.',
  websiteHoneypotLabel: 'Website',
  titleLabel: 'Title',
  titlePlaceholder: 'Short, concise title',
  titleHint: '3–200 characters',
  bodyLabel: 'Description',
  bodyPlaceholder: 'What is it about? What should change?',
  bodyHint: 'At least 10 characters',
  saving: 'Saving…',
  saveChanges: 'Save changes',
} satisfies typeof de

export default en
