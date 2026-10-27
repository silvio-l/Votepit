import type de from './widget.de'

const en = {
  title: 'Feedback',
  close: 'Close',
  loading: 'Loading…',
  loadFailed: 'The board could not be loaded.',
  empty: 'No ideas yet.',
  upvote: 'Vote up',
  downvote: 'Vote down',
  comments: '{count} comments',
  commentSingular: '{count} comment',
  fewVotes: 'Few votes so far – the result can still change a lot',
  approval: '{value}% approval',
  back: 'Back',
  readOnlyHint: 'To vote and comment, the board opens in a new tab.',
  openBoard: 'Open board',
  openIdea: 'Open on the board',
  commentLabel: 'Comment',
  commentSubmit: 'Post comment',
  newIdea: 'New idea',
  titleLabel: 'Title',
  bodyLabel: 'Description',
  submit: 'Submit idea',
  submitted: 'Thanks! Your idea was submitted.',
  submittedPending: 'Thanks! Your idea will appear once the team has approved it.',
  actionFailed: 'That did not work. Please try again.',
  signedInAs: 'Signed in as {name}',
} satisfies typeof de

export default en
