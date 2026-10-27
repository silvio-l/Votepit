import type de from './impressumPage.de'

const en = {
  loading: 'Loading…',
  errorHeading: 'Could not load this page',
  errorLoadFailed: 'Please try again in a moment.',
  notFoundTitle: "This legal notice isn't here right now",
  notFoundDescription:
    'It may have been moved or removed. The homepage is a good place to carry on.',
  retry: 'Try again',
  heading: 'Legal notice',
  subtitle: 'Legal notice for {name}, as provided by its operator.',
  nameLabel: 'Operator',
  addressLabel: 'Address',
  emailLabel: 'Contact',
  noticeHeading: 'Additional information',
  missingTitle: 'No legal notice available',
  missingBody: 'The operator of this board has not provided legal-notice details yet.',
  privacyHeading: 'Data protection (GDPR)',
  privacyBody:
    'This board runs on Votepit. Votepit processes data on behalf of the operator named above, who is the controller ("Verantwortlicher") responsible for this board under the GDPR. For details on how Votepit itself processes data as a platform, see',
  privacyLinkLabel: "Votepit's privacy policy",
} satisfies typeof de

export default en
