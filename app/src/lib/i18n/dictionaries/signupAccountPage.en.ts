import type de from './signupAccountPage.de'

const en = {
  loading: 'Loading…',
  loadError: 'The page could not be loaded.',
  alreadyHasAccountTitle: 'You already have an account',
  alreadyHasAccountBody:
    'Only one account is intended per login. You are already signed in — head to your account below.',
  doneTitle: 'Your account is ready',
  doneBodyBeforePath: 'Your first board is live at',
  doneBodyAfterPath: '',
  manageAccountCta: 'Go to management',
  heading: 'Set up your account',
  subheading: 'An account name + slug, and your first board — you’ll automatically become owner.',
  accountLegend: 'Account',
  nameLabel: 'Name',
  accountNamePlaceholder: 'e.g. Acme Inc',
  slugLabel: 'Slug',
  accountSlugPlaceholder: 'e.g. acme',
  accountSlugHint: 'Appears in the URL: {host}/your-slug/…',
  firstBoardLegend: 'First board',
  boardNamePlaceholder: 'e.g. Product Feedback',
  boardSlugPlaceholder: 'e.g. product-feedback',
  submitSubmitting: 'Creating…',
  submit: 'Create account',
  stepsAriaLabel: 'Setup progress',
  stepEmail: 'Email verified',
  stepAccount: 'Account & board',
  stepReady: 'Ready',
} satisfies typeof de

export default en
