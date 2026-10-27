import type de from './appealPage.de'

const en = {
  loading: 'Loading…',
  loadError: 'Page could not be loaded.',
  scopeLabel: 'Appeal',
  notFoundError: 'This removal could not be found.',
  notAuthorError: 'Only the original author can appeal this removal.',
  alreadyPendingError: 'An appeal for this removal is already pending review.',
  submitFailed: 'The appeal could not be submitted. Please try again.',
  backToBoard: 'Back to board',
  heading: 'Appeal this removal',
  subtitle:
    'If you believe your content was removed by mistake, describe why here. A moderator will review your appeal.',
  messageLabel: 'Why should this removal be reconsidered?',
  messagePlaceholder: 'Describe why you disagree with the removal…',
  messageHint: '{count} characters',
  submit: 'Submit appeal',
  submitting: 'Submitting…',
  submittedTitle: 'Appeal submitted',
  submittedBody:
    'Thanks, your appeal has been submitted. A moderator will review it and you will be notified of the outcome.',
} satisfies typeof de

export default en
