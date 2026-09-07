export default {
  loading: 'Wird geladen…',
  title: 'Profil',
  subtitle: 'Profilbild, Social-Links, Accounts und Anmeldesicherheit an einem Ort.',
  accountsHeading: 'Deine Accounts',
  noAccounts: 'Du bist noch keinem Account zugeordnet.',
  noAccountsHint:
    'Du kannst trotzdem überall abstimmen und dein Profil verwalten — ein eigenes Board brauchst du dafür nicht.',
  noAccountsCta: 'Eigenes Board erstellen',
  accountColumn: 'Account',
  manageLink: 'Verwalten',
  logoutCta: 'Abmelden',
  profileLoadError:
    'Profilbild, Social-Links und Datenschutz-Einstellung konnten nicht geladen werden.',
  profileLoadRetryCta: 'Erneut versuchen',
  loggedInHint:
    'Aus Datenschutzgründen speichert Votepit deine E-Mail-Adresse nirgends im Klartext (nur als Einweg-Hashwert) — sie kann hier deshalb nicht angezeigt werden.',
  userIdHint: 'Deine User-ID: #{id}',

  // Username (optionaler öffentlicher Anzeigename)
  usernameHeading: 'Anzeigename',
  usernameHint:
    'Optional — wird anstelle von „Voter" bei deinen Ideen und Kommentaren angezeigt, aber nur wenn dein Profil öffentlich sichtbar ist. Global eindeutig.',
  usernameLabel: 'Anzeigename',
  usernamePlaceholder: 'z. B. maxmustermann',
  usernameSave: 'Speichern',
  usernameSaving: 'Wird gespeichert…',
  usernameSaved: 'Gespeichert.',
  usernameTakenError: 'Dieser Anzeigename ist bereits vergeben.',
  usernameInvalidError:
    'Bitte 3–30 Zeichen angeben, beginnend mit einem Buchstaben (Buchstaben, Ziffern, Unterstrich).',
  usernameGenericError: 'Etwas ist schiefgelaufen. Bitte erneut versuchen.',

  // Avatar (profile-avatar-social)
  avatarHeading: 'Profilbild',
  avatarHint: 'JPG, PNG, GIF oder WebP, max. 5 MB. Wird automatisch auf 256×256 zugeschnitten.',
  avatarUpload: 'Bild hochladen',
  avatarUploading: 'Wird hochgeladen…',
  avatarRemove: 'Entfernen',
  avatarRemoving: 'Wird entfernt…',
  avatarFileTooLarge: 'Die Datei ist größer als 5 MB.',
  avatarInvalidType:
    'Bitte ein Bild auswählen (JPG, PNG, GIF oder WebP). SVG wird nicht unterstützt.',
  avatarUploadError: 'Das Bild konnte nicht verarbeitet werden. Bitte ein anderes Bild versuchen.',
  avatarGenericError: 'Etwas ist schiefgelaufen. Bitte erneut versuchen.',

  // Social links (profile-avatar-social security redesign)
  socialLinksHeading: 'Social-Links',
  socialLinksHint:
    'Bis zu 4 feste Profil-Links — gib deinen Handle oder deine Domain ein, nie eine vollständige URL.',
  socialLinksWebsiteLabel: 'Website',
  socialLinksXLabel: 'X (Twitter)',
  socialLinksYoutubeLabel: 'YouTube',
  socialLinksGithubLabel: 'GitHub',
  socialLinksHandlePlaceholder: 'Handle',
  socialLinksUsernamePlaceholder: 'Benutzername',
  socialLinksSave: 'Speichern',
  socialLinksSaving: 'Wird gespeichert…',
  socialLinksSaved: 'Gespeichert.',
  socialLinksInvalidWebsite: 'Bitte eine reine Domain angeben, z. B. example.com.',
  socialLinksInvalidXHandle:
    'Bitte einen gültigen X-Handle angeben (Buchstaben, Ziffern, Unterstrich, max. 15 Zeichen).',
  socialLinksInvalidYoutubeHandle:
    'Bitte einen gültigen YouTube-Handle ohne „@" angeben (3–30 Zeichen).',
  socialLinksInvalidGithubUsername:
    'Bitte einen gültigen GitHub-Benutzernamen angeben (max. 39 Zeichen).',
  socialLinksSaveRejected: 'Einer der Werte wurde abgelehnt. Bitte das markierte Feld prüfen.',
  socialLinksGenericError: 'Etwas ist schiefgelaufen. Bitte erneut versuchen.',

  // Datenschutz (profile-visibility)
  privacyHeading: 'Datenschutz',
  privacyToggleLabel: 'Profil öffentlich sichtbar',
  privacyStateVisible:
    'Andere sehen bei deinen Ideen und Kommentaren dein Profilbild und deine Social-Links.',
  privacyStateAnonymous:
    'Du erscheinst bei Ideen und Kommentaren als „Voter" — ohne Profilbild und Social-Links.',
  privacyHint:
    'Standardmäßig bleibst du anonym. Schaltest du dein Profil sichtbar, können andere bei deinen Ideen und Kommentaren dein Profilbild und deine Social-Links sehen und dein Profil öffnen.',
  privacyRoleBadgeNote:
    'Unabhängig davon: Bist du Owner oder Moderator eines Accounts, wird diese Rolle dort immer als Badge angezeigt.',
  privacySaved: 'Gespeichert.',
  privacyGenericError: 'Die Einstellung konnte nicht gespeichert werden. Bitte erneut versuchen.',

  // Benachrichtigungen (notification-preferences)
  notificationsHeading: 'Benachrichtigungen',
  notificationsHint:
    'Lege fest, worüber du benachrichtigt werden möchtest — in der App und per E-Mail.',
  notificationsIdeaCommentLabel: 'Neuer Kommentar auf meiner Idee',
  notificationsThreadReplyLabel: 'Neue Antwort in einem Thread, in dem ich kommentiert habe',
  notificationsAbuseReportLabel: 'Neue Meldung (Operator)',
  notificationsSupportTicketLabel: 'Neues oder beantwortetes Support-Ticket (Operator)',
  notificationsInAppColumn: 'In-App',
  notificationsEmailColumn: 'E-Mail',
  notificationsEmailFieldLabel: 'Benachrichtigungs-E-Mail',
  notificationsEmailPlaceholder: 'name@example.com',
  notificationsEmailSubmit: 'Bestätigungslink senden',
  notificationsEmailSubmitting: 'Wird gesendet…',
  notificationsEmailSentHint:
    'Bestätigungsmail verschickt — bitte den Link in der E-Mail anklicken.',
  notificationsEmailInvalidError: 'Bitte eine gültige E-Mail-Adresse angeben.',
  notificationsEmailConfirmedPrefix: 'Bestätigte E-Mail:',
  notificationsEmailRemove: 'E-Mail entfernen',
  notificationsEmailRemoving: 'Wird entfernt…',
  notificationsSaved: 'Gespeichert.',
  notificationsGenericError:
    'Die Einstellung konnte nicht gespeichert werden. Bitte erneut versuchen.',

  // Passwort
  passwordHeading: 'Passwort',
  passwordSubtitle: 'Optional — zusätzlich zum Magic-Link.',
  currentPasswordLabel: 'Aktuelles Passwort',
  newPasswordLabel: 'Neues Passwort',
  setPasswordLabel: 'Passwort',
  confirmPasswordLabel: 'Passwort bestätigen',
  passwordMinLengthHint: 'Mindestens 10 Zeichen.',
  passwordMismatchError: 'Die Passwörter stimmen nicht überein.',
  passwordSaveCta: 'Speichern',
  passwordSaving: 'Wird gespeichert…',
  passwordSavedSuccess: 'Passwort gespeichert.',
  passwordGenericError: 'Das Passwort konnte nicht gespeichert werden.',
  passwordResetLinkCta: 'Stattdessen Reset-Link per E-Mail zusenden',
  passwordResetLinkCancelCta: 'Abbrechen',
  passwordResetLinkEmailLabel: 'E-Mail bestätigen',
  passwordResetLinkEmailHint:
    'Wir können deine Adresse nicht nachschlagen — bitte gib sie zur Bestätigung erneut ein.',
  passwordResetLinkSendCta: 'Reset-Link senden',
  passwordResetLinkSending: 'Wird gesendet…',
  passwordResetLinkSuccess: 'Prüfe dein Postfach — ein Reset-Link ist unterwegs.',
  passwordResetLinkMismatchError: 'Das stimmt nicht mit der E-Mail dieses Kontos überein.',
  passwordResetLinkGenericError:
    'Der Reset-Link konnte nicht gesendet werden. Bitte erneut versuchen.',

  // 2FA
  totpHeading: 'Zwei-Faktor-Authentifizierung',
  totpSubtitle: 'Optional — schützt dein Konto zusätzlich mit einer Authenticator-App.',
  totpEnableCta: '2FA aktivieren',
  totpEnabledStatus: '2FA ist aktiv.',
  totpSetupInstructions:
    'Scanne den QR-Code mit deiner Authenticator-App (z. B. Google Authenticator, 1Password) und gib den angezeigten Code ein.',
  totpQrAlt: 'QR-Code für die Authenticator-App',
  totpManualEntryHint: 'Oder manuell eingeben:',
  totpCodeLabel: 'Code',
  totpConfirmCta: 'Bestätigen',
  totpConfirming: 'Wird geprüft…',
  totpCancelCta: 'Abbrechen',
  totpConfirmError: 'Der Code ist ungültig oder abgelaufen.',
  totpGenericError: 'Die 2FA-Einrichtung konnte nicht gestartet werden.',
  totpRegenerateCta: 'Backup-Codes neu erzeugen',
  totpDisableCta: '2FA deaktivieren',
  totpDisableConfirmPrompt: 'Bestätige die Deaktivierung mit deinem Passwort oder einem 2FA-Code.',
  totpRegenerateConfirmPrompt:
    'Bestätige das Neuerzeugen der Backup-Codes mit deinem Passwort oder einem 2FA-Code.',
  totpConfirmationFailedError: 'Bestätigung fehlgeschlagen.',
  useCurrentPasswordInstead: 'Stattdessen das aktuelle Passwort verwenden',
  useCodeInsteadOfPassword: 'Stattdessen einen 2FA-Code verwenden',
  backupCodesTitle: 'Backup-Codes',
  backupCodesSetupHint:
    'Diese 10 Codes werden nur jetzt angezeigt. Speichere sie an einem sicheren Ort — jeder Code funktioniert einmal, falls du keinen Zugriff auf deine Authenticator-App hast.',
  backupCodesRegenerateHint:
    'Die alten Backup-Codes sind ab sofort ungültig. Speichere diese neuen Codes an einem sicheren Ort.',
  backupCodesAckCta: 'Codes gespeichert',
}
