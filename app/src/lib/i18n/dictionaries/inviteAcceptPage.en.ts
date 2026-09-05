import type de from './inviteAcceptPage.de'

const en = {
  invalidOrExpired: 'The invite link is invalid or expired.',
  acceptFailedFallback: 'The invite could not be accepted.',
  accepting: 'Accepting invite…',
  doneTitle: 'Welcome to the team',
  doneBodyOwner: 'You are now the owner of this account.',
  doneBodyAdmin: 'You are now an admin of this account.',
  doneBodyModerator: 'You are now a moderator of this account.',
  doneBodyMember: "You now have access to this account's private boards.",
  goToMembers: 'Go to members overview',
  goToBoard: 'Go to board',
  errorTitle: 'Invite expired',
  goToHome: 'Go to homepage',
  mismatchTitle: 'Wrong account signed in',
  mismatchBody:
    'This invite is for a different email address. Log in with the invited account to accept it.',
  mismatchCurrentAccount: 'Currently signed in as: {publicId}',
  mismatchAliasHint:
    'If you did receive this email yourself: a forward or an alias mailbox counts as a different address than the one the invite was sent to.',
  switchAccountCta: 'Log out and switch account',
  switchingAccount: 'Logging out…',
} satisfies typeof de

export default en
