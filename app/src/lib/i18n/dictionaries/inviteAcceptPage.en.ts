import type de from './inviteAcceptPage.de'

const en = {
  invalidOrExpired: 'The invite link is invalid or expired.',
  acceptFailedFallback: 'The invite could not be accepted.',
  accepting: 'Accepting invite…',
  doneTitle: 'Welcome to the team',
  doneBody: 'You are now a moderator of this account.',
  goToMembers: 'Go to members overview',
  errorTitle: 'Invite expired',
  goToHome: 'Go to homepage',
  mismatchTitle: 'Wrong account signed in',
  mismatchBody:
    'This invite is for a different email address. Log in with the invited account to accept it.',
  switchAccountCta: 'Log out and switch account',
  switchingAccount: 'Logging out…',
} satisfies typeof de

export default en
