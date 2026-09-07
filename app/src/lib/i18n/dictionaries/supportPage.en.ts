import type de from './supportPage.de'

const en = {
  loading: 'Loading…',
  loadError: 'Could not load Support.',
  title: 'Support',
  subtitle:
    'Contact our team directly from the dashboard — we get back to you as soon as possible.',
  accessDeniedTitle: 'No access',
  accessDeniedBody: 'You are not a member of this account.',

  formHeading: 'New request',
  categoryLabel: 'Category',
  subjectLabel: 'Subject',
  messageLabel: 'Message',
  submit: 'Send',
  submitting: 'Sending…',
  submitSuccess:
    "Thanks! We've received your request. Once we reply, you'll find it in your inbox.",
  submitFailed: 'The request could not be sent. Please try again.',

  faqDeflectionHeading: 'This might already help',

  ticketsHeading: 'Your requests ({count})',
  noTickets: "You haven't submitted a support request yet.",
  subjectColumn: 'Subject',
  categoryColumn: 'Category',
  statusColumn: 'Status',
  updatedColumn: 'Last active',
  ticketNotFoundTitle: 'Ticket not found',
  ticketNotFoundBody:
    "This support request doesn't exist (anymore) or doesn't belong to this account.",
  threadHeading: 'Conversation',
  threadLoading: 'Loading conversation…',
  threadLoadError: 'Could not load the conversation.',
  fromYou: 'You',
  fromSupport: 'Support',
  replyLabel: 'Your reply',
  replyPlaceholder: 'Write a message…',
  replySubmit: 'Send',
  replySubmitting: 'Sending…',
  replyFailed: 'The message could not be sent. Please try again.',

  'category.billing': 'Billing',
  'category.technical': 'Technical issue',
  'category.account': 'Account & membership',
  'category.feature_request': 'Feature request',
  'category.privacy': 'Privacy',
  'category.other': 'Other',

  'status.open': 'Open',
  'status.answered': 'Answered',
  'status.closed': 'Closed',
} satisfies typeof de

export default en
