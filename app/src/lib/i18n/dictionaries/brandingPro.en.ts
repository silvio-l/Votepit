import type de from './brandingPro.de'

const en = {
  heading: 'Advanced branding (Pro)',
  subtitle:
    'Typography, corners and density of your board page, your own wording for your community, header links and the roadmap switch.',
  presetsHeading: 'Typography, corners and density',
  typographyLabel: 'Typography',
  shapeLabel: 'Corners',
  densityLabel: 'Density',
  typoDefault: 'Votepit default',
  typoInter: 'Inter (calm, neutral)',
  typoMono: 'Mono (technical)',
  shapeRounded: 'Rounded (default)',
  shapeSoft: 'Soft',
  shapeSquare: 'Square',
  densityComfortable: 'Comfortable (default)',
  densityCompact: 'Compact',
  wordingHeading: 'Your wording',
  wordingHint:
    'Leave empty to use Votepit’s text. Each language only uses what you enter for it. Plain text only, no characters like < > { }.',
  wordingLocaleLabel: 'Language',
  wordingIdeaSingular: 'Term for “idea” (singular)',
  wordingIdeaPlural: 'Term for “ideas” (plural)',
  wordingSubmitButton: 'Submit button text',
  wordingEmptyState: 'Text when there are no ideas yet',
  wordingThankYou: 'Thank-you text after submitting',
  wordingVoteFor: 'Word for “For”',
  wordingVoteAgainst: 'Word for “Against”',
  linksHeading: 'Header links',
  linksHint:
    'Up to 5 links at the top of your board, e.g. to your rules or home page. Addresses start with https://. Empty rows are ignored.',
  linkUrlLabel: 'Link address (https://…)',
  linkLabelLabel: 'Link text',
  roadmapHiddenLabel: 'Hide the roadmap from visitors',
  roadmapHiddenHint:
    'Visitors then see no roadmap link and get a 404. You and admins still see it.',
  lockedHint: 'Not included in your current plan. Stored values are kept.',
  save: 'Save',
  saving: 'Saving…',
  saved: 'Saved.',
  saveFailed: 'Saving failed. Please try again.',
  validationError: 'Please check the highlighted fields.',
  planLocked: 'This section is not included in your current plan.',
  loadFailed: 'The settings could not be loaded just now.',
  planUpgradeLinkLabel: 'View plans',
} satisfies typeof de

export default en
