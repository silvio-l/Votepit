import type de from './marketingConsent.de'

const en = {
  profileHeading: 'Product news and offers',
  profileHint:
    'Occasional mails about new Votepit features and offers. Voluntary and withdrawable at any time. The confirmation goes to your confirmed notification e-mail address.',
  toggleLabel: 'Receive product news and offers by e-mail',
  statusNone: 'Not enabled.',
  statusPending:
    'Confirmation pending. We have sent you a confirmation link by e-mail. The link is valid for 48 hours.',
  statusConfirmed: 'Active. You can unsubscribe at any time.',
  statusWithdrawn: 'Not enabled.',
  needsNotificationEmail:
    'Confirm a notification e-mail address first, so we can send the confirmation there.',
  saveError: 'The setting could not be saved. Please try again.',
  loadError: 'Your setting could not be loaded.',
  signupCheckboxLabel:
    'I want to receive Votepit product news and offers by e-mail (optional, withdrawable at any time).',
  signupNotice:
    'We use your e-mail address for recommendations of our own, similar Votepit products. You can object at any time; the only costs you incur are the transmission costs at the basic rates.',
  confirming: 'Confirming…',
  doneTitle: 'Product news confirmed',
  doneBody:
    'Thank you. From now on you will receive Votepit product news and offers by e-mail. You can withdraw this at any time in your profile settings.',
  errorTitle: 'Confirmation not possible',
  invalidOrExpired:
    'The link is invalid or has expired. Request a new confirmation in your profile settings.',
  confirmFailedFallback: 'The confirmation could not be completed. Please try again later.',
  backToProfile: 'Back to profile',
} satisfies typeof de

export default en
