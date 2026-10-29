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
  subheading:
    'A name and an address for your account, plus your first board. You become the owner automatically.',
  accountLegend: 'Account',
  nameLabel: 'Name',
  accountNamePlaceholder: 'e.g. Acme Inc',
  slugLabel: 'Address',
  accountSlugPlaceholder: 'e.g. acme',
  accountSlugHint: 'Your address: {url}',
  legalOperatorLegend: 'Who runs this board?',
  legalOperatorLegendHint:
    'Your board is public, so it needs a contact that appears in the imprint. That way visitors know who stands behind it.',
  legalOperatorNameLabel: 'Operator name',
  legalOperatorNamePlaceholder: 'e.g. Acme Inc, or your own name',
  legalOperatorAddressLabel: 'Address',
  legalOperatorAddressPlaceholder: '123 Main Street\nAnytown, ST 12345\nUnited States',
  legalOperatorEmailLabel: 'Contact email',
  legalOperatorEmailPlaceholder: 'contact@your-domain.com',
  firstBoardLegend: 'Your first board',
  boardNamePlaceholder: 'e.g. Our Ideas or Summer Party Ideas',
  boardSlugPlaceholder: 'e.g. our-ideas',
  submitSubmitting: 'Creating…',
  submit: 'Create account',
  termsCheckboxLabel:
    'I accept the Terms of Service, the Acceptable Use Policy and the Privacy Policy.',
  termsLinkAgb: 'Terms of Service',
  termsLinkAup: 'Acceptable Use Policy',
  termsLinkPrivacy: 'Privacy Policy',
  stepsAriaLabel: 'Setup progress',
  stepEmail: 'Email verified',
  stepAccount: 'Account & board',
  stepReady: 'Ready',
} satisfies typeof de

export default en
