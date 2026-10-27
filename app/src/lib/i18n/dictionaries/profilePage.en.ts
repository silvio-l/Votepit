import type de from './profilePage.de'

const en = {
  loading: 'Loading…',
  title: 'Profile',
  subtitle: 'Your picture, social links, accounts, and sign-in security, all in one place.',
  accountsHeading: 'Your accounts',
  noAccounts: 'You are not a member of any account yet.',
  noAccountsHint:
    "You can still vote everywhere and manage your profile — you don't need your own board for that.",
  noAccountsCta: 'Create your own board',
  accountColumn: 'Account',
  manageLink: 'Manage',
  logoutCta: 'Log out',
  profileLoadError: 'Could not load your picture, social links, and privacy setting.',
  profileLoadRetryCta: 'Try again',
  userIdHint: 'Your user ID: #{id}',
  loggedInHint:
    "For privacy, Votepit never stores your email address in plain text anywhere (only a one-way hash) — it can't be shown here.",

  // Username (optional public display name)
  usernameHeading: 'Display name',
  usernameHint:
    'Optional — shown instead of "Voter" on your ideas and comments, but only while your profile is publicly visible. Globally unique.',
  usernameLabel: 'Display name',
  usernamePlaceholder: 'e.g. janedoe',
  usernameSave: 'Save',
  usernameSaving: 'Saving…',
  usernameSaved: 'Saved.',
  usernameTakenError: 'This display name is already taken.',
  usernameInvalidError:
    'Please use 3–30 characters, starting with a letter (letters, digits, underscore).',
  usernameGenericError: 'Something went wrong. Please try again.',

  // Avatar (profile-avatar-social)
  avatarHeading: 'Profile picture',
  avatarHint: 'JPG, PNG, GIF, or WebP, max 5 MB. Automatically cropped to 256×256.',
  avatarUpload: 'Upload picture',
  avatarUploading: 'Uploading…',
  avatarRemove: 'Remove',
  avatarRemoving: 'Removing…',
  avatarFileTooLarge: 'The file is larger than 5 MB.',
  avatarInvalidType: 'Please choose an image (JPG, PNG, GIF, or WebP). SVG is not supported.',
  avatarUploadError: 'The image could not be processed. Please try a different image.',
  avatarGenericError: 'Something went wrong. Please try again.',

  // Social links (profile-avatar-social security redesign)
  socialLinksHeading: 'Social links',
  socialLinksHint: 'Up to 4 fixed profile links — enter your handle or domain, never a full URL.',
  socialLinksWebsiteLabel: 'Website',
  socialLinksXLabel: 'X (Twitter)',
  socialLinksYoutubeLabel: 'YouTube',
  socialLinksGithubLabel: 'GitHub',
  socialLinksHandlePlaceholder: 'handle',
  socialLinksUsernamePlaceholder: 'username',
  socialLinksSave: 'Save',
  socialLinksSaving: 'Saving…',
  socialLinksSaved: 'Saved.',
  socialLinksInvalidWebsite: 'Please enter a bare domain, e.g. example.com.',
  socialLinksInvalidXHandle:
    'Please enter a valid X handle (letters, digits, underscore, max 15 characters).',
  socialLinksInvalidYoutubeHandle:
    'Please enter a valid YouTube handle without "@" (3–30 characters).',
  socialLinksInvalidGithubUsername: 'Please enter a valid GitHub username (max 39 characters).',
  socialLinksSaveRejected: 'One of the values was rejected. Please check the highlighted field.',
  socialLinksGenericError: 'Something went wrong. Please try again.',

  // Linked accounts (OAuth login)
  oauthHeading: 'Linked accounts',
  oauthIntro: 'Google or GitHub accounts you can sign in with.',
  oauthEmpty: 'No linked account.',
  oauthLinkedSince: 'Linked since {date}',
  oauthUnlink: 'Unlink',
  oauthUnlinkCancel: 'Cancel',
  oauthUnlinkFailed: 'Unlinking failed. Please try again.',
  oauthUnlinkConfirmTitle: 'Unlink this account?',
  oauthUnlinkConfirmBody:
    'Signing in with {provider} will no longer work — you can still always sign in with your email address via a magic link.',

  // Privacy (profile-visibility)
  privacyHeading: 'Privacy',
  privacyToggleLabel: 'Profile publicly visible',
  privacyStateVisible:
    'Others see your profile picture and social links on your ideas and comments.',
  privacyStateAnonymous:
    'You appear on ideas and comments as "Voter" — without a profile picture or social links.',
  privacyHint:
    'You stay anonymous by default. If you make your profile visible, others can see your profile picture and social links on your ideas and comments and open your profile.',
  privacyRoleBadgeNote:
    'Independently of this: if you are an owner or moderator of an account, that role is always shown there as a badge.',
  privacySaved: 'Saved.',
  privacyGenericError: 'The setting could not be saved. Please try again.',

  // Notifications (notification-preferences)
  notificationsHeading: 'Notifications',
  notificationsHint: 'Choose what you want to be notified about — in the app and by email.',
  notificationsIdeaCommentLabel: 'New comment on my idea',
  notificationsThreadReplyLabel: 'New reply in a thread I commented on',
  notificationsAbuseReportLabel: 'New content report (platform operator)',
  notificationsSupportTicketLabel: 'New or answered support ticket (platform operator)',
  notificationsWeeklyDigestLabel: 'Weekly digest of my boards',
  notificationsWeeklyDigestHint:
    'Once a week: new, top, most rejected and most polarising ideas. On by default for owners; weeks without activity are skipped.',
  notificationsInAppColumn: 'In-app',
  notificationsEmailColumn: 'Email',
  notificationsEmailFieldLabel: 'Notification email',
  notificationsEmailPlaceholder: 'name@example.com',
  notificationsEmailSubmit: 'Send confirmation link',
  notificationsEmailSubmitting: 'Sending…',
  notificationsEmailSentHint: 'Confirmation email sent — please click the link in the email.',
  notificationsEmailInvalidError: 'Please enter a valid email address.',
  notificationsEmailConfirmedPrefix: 'Confirmed email:',
  notificationsEmailRemove: 'Remove email',
  notificationsEmailRemoving: 'Removing…',
  notificationsSaved: 'Saved.',
  notificationsGenericError: 'The setting could not be saved. Please try again.',

  // Password
  passwordHeading: 'Password',
  passwordSubtitle: 'Optional — in addition to the email sign-in link.',
  currentPasswordLabel: 'Current password',
  newPasswordLabel: 'New password',
  setPasswordLabel: 'Password',
  confirmPasswordLabel: 'Confirm password',
  passwordMinLengthHint: 'At least 10 characters.',
  passwordMismatchError: 'The passwords do not match.',
  passwordSaveCta: 'Save',
  passwordSaving: 'Saving…',
  passwordSavedSuccess: 'Password saved.',
  passwordGenericError: 'The password could not be saved.',
  passwordResetLinkCta: 'Send me a reset link by email instead',
  passwordResetLinkCancelCta: 'Cancel',
  passwordResetLinkEmailLabel: 'Confirm your email',
  passwordResetLinkEmailHint: "We can't look up your address — please type it again to confirm.",
  passwordResetLinkSendCta: 'Send reset link',
  passwordResetLinkSending: 'Sending…',
  passwordResetLinkSuccess: 'Check your inbox — a reset link is on its way.',
  passwordResetLinkMismatchError: "That doesn't match this account's email.",
  passwordResetLinkGenericError: 'The reset link could not be sent. Please try again.',

  // 2FA
  totpHeading: 'Two-factor authentication',
  totpSubtitle: 'Optional — adds extra protection with an authenticator app.',
  totpEnableCta: 'Enable 2FA',
  totpEnabledStatus: '2FA is active.',
  totpSetupInstructions:
    'Scan the QR code with your authenticator app (e.g. Google Authenticator, 1Password) and enter the code it shows.',
  totpQrAlt: 'QR code for the authenticator app',
  totpManualEntryHint: 'Or enter manually:',
  totpCodeLabel: 'Code',
  totpConfirmCta: 'Confirm',
  totpConfirming: 'Verifying…',
  totpCancelCta: 'Cancel',
  totpConfirmError: 'The code is invalid or expired.',
  totpGenericError: 'Could not start 2FA setup.',
  totpRegenerateCta: 'Regenerate backup codes',
  totpDisableCta: 'Disable 2FA',
  totpDisableConfirmPrompt: 'Confirm disabling with your password or a 2FA code.',
  totpRegenerateConfirmPrompt:
    'Confirm regenerating backup codes with your password or a 2FA code.',
  totpConfirmationFailedError: 'Confirmation failed.',
  useCurrentPasswordInstead: 'Use your current password instead',
  useCodeInsteadOfPassword: 'Use a 2FA code instead',
  backupCodesTitle: 'Backup codes',
  backupCodesSetupHint:
    'These 10 codes are shown only now. Save them somewhere safe — each works once if you lose access to your authenticator app.',
  backupCodesRegenerateHint:
    'The old backup codes are now invalid. Save these new ones somewhere safe.',
  backupCodesAckCta: 'Codes saved',
  myDataHeading: 'Your data',
  myDataBody:
    'Download everything Votepit holds about you — profile, ideas, comments, votes, reactions, notification settings and linked sign-in providers, across all accounts (JSON).',
  myDataDownload: 'Download my data',
  myDataDownloading: 'Preparing…',
  myDataDownloadFailed: 'The download failed. Please try again.',
  deleteMeHeading: 'Delete account',
  deleteMeBody:
    'Permanently deletes your Votepit account after 48 hours. Your ideas and comments stay up as “Deleted user”, your votes keep counting anonymously — all personal data is removed.',
  deleteMeButton: 'Delete my account',
  deleteMeOwnerBlocked:
    'You still own an account. Delete it in its account settings first (or contact support to transfer it).',
  deleteMeConfirmLabel: 'To confirm, enter your email address',
  deleteMeConfirmSubmit: 'Confirm deletion',
  deleteMeSubmitting: 'Scheduling…',
  deleteMeCancel: 'Cancel',
  deleteMeFailed: 'The deletion could not be scheduled.',
  deleteMePendingTitle: 'Deletion scheduled',
  deleteMePendingBody:
    'Your account will be permanently deleted on {date}. Until then you can cancel the deletion.',
  deleteMeUndo: 'Cancel deletion',
  deleteMeUndoing: 'Cancelling…',
  deleteMeUndoFailed: 'The deletion could not be cancelled.',
} satisfies typeof de

export default en
