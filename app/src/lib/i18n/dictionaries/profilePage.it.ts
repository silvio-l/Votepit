// machine-drafted, needs native review
import type de from './profilePage.de'

const it = {
  loading: 'Caricamento…',
  title: 'Profilo',
  subtitle:
    'La tua immagine, i link social, gli account e la sicurezza dell’accesso, tutto in un unico posto.',
  accountsHeading: 'I tuoi account',
  noAccounts: 'Non sei ancora membro di nessun account.',
  noAccountsHint:
    'Puoi comunque votare ovunque e gestire il tuo profilo — non ti serve una bacheca tua per questo.',
  noAccountsCta: 'Crea la tua bacheca',
  accountColumn: 'Account',
  manageLink: 'Gestisci',
  logoutCta: 'Esci',
  profileLoadError:
    'Impossibile caricare la tua immagine, i link social e l’impostazione della privacy.',
  profileLoadRetryCta: 'Riprova',
  userIdHint: 'Il tuo ID utente: #{id}',
  loggedInHint:
    'Per motivi di privacy, Votepit non memorizza mai il tuo indirizzo e-mail in chiaro (solo un hash non reversibile) — per questo non può essere mostrato qui.',

  // Username (optional public display name)
  usernameHeading: 'Nome visualizzato',
  usernameHint:
    'Facoltativo — mostrato al posto di "Votante" su idee e commenti, ma solo finché il tuo profilo è visibile pubblicamente. Unico a livello globale.',
  usernameLabel: 'Nome visualizzato',
  usernamePlaceholder: 'es. mariorossi',
  usernameSave: 'Salva',
  usernameSaving: 'Salvataggio…',
  usernameSaved: 'Salvato.',
  usernameTakenError: 'Questo nome visualizzato è già in uso.',
  usernameInvalidError:
    'Usa da 3 a 30 caratteri, iniziando con una lettera (lettere, cifre, trattino basso).',
  usernameGenericError: 'Qualcosa è andato storto. Riprova.',

  // Avatar (profile-avatar-social)
  avatarHeading: 'Immagine del profilo',
  avatarHint: 'JPG, PNG, GIF o WebP, max 5 MB. Ritagliata automaticamente a 256×256.',
  avatarUpload: 'Carica immagine',
  avatarUploading: 'Caricamento…',
  avatarRemove: 'Rimuovi',
  avatarRemoving: 'Rimozione…',
  avatarFileTooLarge: 'Il file supera i 5 MB.',
  avatarInvalidType: 'Scegli un’immagine (JPG, PNG, GIF o WebP). SVG non è supportato.',
  avatarUploadError: 'Impossibile elaborare l’immagine. Prova con un’altra immagine.',
  avatarGenericError: 'Qualcosa è andato storto. Riprova.',

  // Social links (profile-avatar-social security redesign)
  socialLinksHeading: 'Link social',
  socialLinksHint:
    'Fino a 4 link di profilo fissi — inserisci il tuo handle o dominio, mai un URL completo.',
  socialLinksWebsiteLabel: 'Sito web',
  socialLinksXLabel: 'X (Twitter)',
  socialLinksYoutubeLabel: 'YouTube',
  socialLinksGithubLabel: 'GitHub',
  socialLinksHandlePlaceholder: 'nome utente',
  socialLinksUsernamePlaceholder: 'nome utente',
  socialLinksSave: 'Salva',
  socialLinksSaving: 'Salvataggio…',
  socialLinksSaved: 'Salvato.',
  socialLinksInvalidWebsite: 'Inserisci solo il dominio, es. example.com.',
  socialLinksInvalidXHandle:
    'Inserisci un handle X valido (lettere, cifre, trattino basso, max 15 caratteri).',
  socialLinksInvalidYoutubeHandle: 'Inserisci un handle YouTube valido senza "@" (3–30 caratteri).',
  socialLinksInvalidGithubUsername: 'Inserisci un nome utente GitHub valido (max 39 caratteri).',
  socialLinksSaveRejected: 'Uno dei valori è stato rifiutato. Controlla il campo evidenziato.',
  socialLinksGenericError: 'Qualcosa è andato storto. Riprova.',

  // Linked accounts (OAuth login)
  oauthHeading: 'Account collegati',
  oauthIntro: 'Account Google o GitHub con cui puoi accedere.',
  oauthEmpty: 'Nessun account collegato.',
  oauthLinkedSince: 'Collegato dal {date}',
  oauthUnlink: 'Scollega',
  oauthUnlinkCancel: 'Annulla',
  oauthUnlinkFailed: 'Scollegamento non riuscito. Riprova.',
  oauthUnlinkConfirmTitle: 'Scollegare questo account?',
  oauthUnlinkConfirmBody:
    'L’accesso con {provider} non funzionerà più — potrai comunque accedere sempre con il tuo indirizzo e-mail tramite magic link.',

  // Privacy (profile-visibility)
  privacyHeading: 'Privacy',
  privacyToggleLabel: 'Profilo visibile pubblicamente',
  privacyStateVisible:
    'Gli altri vedono la tua immagine del profilo e i tuoi link social su idee e commenti.',
  privacyStateAnonymous:
    'Su idee e commenti appari come "Votante" — senza immagine del profilo né link social.',
  privacyHint:
    'Per impostazione predefinita resti anonimo. Se rendi visibile il tuo profilo, gli altri possono vedere la tua immagine del profilo e i tuoi link social su idee e commenti e aprire il tuo profilo.',
  privacyRoleBadgeNote:
    'Indipendentemente da ciò: se sei proprietario o moderatore di un account, quel ruolo viene sempre mostrato lì come badge.',
  privacySaved: 'Salvato.',
  privacyGenericError: 'Impossibile salvare l’impostazione. Riprova.',

  // Notifications (notification-preferences)
  notificationsHeading: 'Notifiche',
  notificationsHint: 'Scegli per cosa vuoi ricevere notifiche — nell’app e via e-mail.',
  notificationsIdeaCommentLabel: 'Nuovo commento alla mia idea',
  notificationsThreadReplyLabel: 'Nuova risposta in una discussione che ho commentato',
  notificationsAbuseReportLabel: 'Nuova segnalazione di contenuti (gestore della piattaforma)',
  notificationsSupportTicketLabel:
    'Ticket di supporto nuovo o con risposta (gestore della piattaforma)',
  notificationsWeeklyDigestLabel: 'Riepilogo settimanale delle mie bacheche',
  notificationsWeeklyDigestHint:
    'Una volta a settimana: idee nuove, più votate, più respinte e più divisive. Attivo per impostazione predefinita per i proprietari; le settimane senza attività vengono saltate.',
  notificationsInAppColumn: 'Nell’app',
  notificationsEmailColumn: 'E-mail',
  notificationsEmailFieldLabel: 'E-mail per le notifiche',
  notificationsEmailPlaceholder: 'nome@example.com',
  notificationsEmailSubmit: 'Invia link di conferma',
  notificationsEmailSubmitting: 'Invio…',
  notificationsEmailSentHint: 'E-mail di conferma inviata — clicca sul link nell’e-mail.',
  notificationsEmailInvalidError: 'Inserisci un indirizzo e-mail valido.',
  notificationsEmailConfirmedPrefix: 'E-mail confermata:',
  notificationsEmailRemove: 'Rimuovi e-mail',
  notificationsEmailRemoving: 'Rimozione…',
  notificationsSaved: 'Salvato.',
  notificationsGenericError: 'Impossibile salvare l’impostazione. Riprova.',

  // Password
  passwordHeading: 'Password',
  passwordSubtitle: 'Facoltativa — in aggiunta al link di accesso via e-mail.',
  currentPasswordLabel: 'Password attuale',
  newPasswordLabel: 'Nuova password',
  setPasswordLabel: 'Password',
  confirmPasswordLabel: 'Conferma password',
  passwordMinLengthHint: 'Almeno 10 caratteri.',
  passwordMismatchError: 'Le password non corrispondono.',
  passwordSaveCta: 'Salva',
  passwordSaving: 'Salvataggio…',
  passwordSavedSuccess: 'Password salvata.',
  passwordGenericError: 'Impossibile salvare la password.',
  passwordResetLinkCta: 'Inviami invece un link di reimpostazione via e-mail',
  passwordResetLinkCancelCta: 'Annulla',
  passwordResetLinkEmailLabel: 'Conferma la tua e-mail',
  passwordResetLinkEmailHint:
    'Non possiamo consultare il tuo indirizzo — digitalo di nuovo per confermare.',
  passwordResetLinkSendCta: 'Invia link di reimpostazione',
  passwordResetLinkSending: 'Invio…',
  passwordResetLinkSuccess:
    'Controlla la tua posta in arrivo — un link di reimpostazione è in arrivo.',
  passwordResetLinkMismatchError: 'Non corrisponde all’e-mail di questo account.',
  passwordResetLinkGenericError: 'Impossibile inviare il link di reimpostazione. Riprova.',

  // 2FA
  totpHeading: 'Autenticazione a due fattori',
  totpSubtitle: 'Facoltativa — aggiunge una protezione extra con un’app di autenticazione.',
  totpEnableCta: 'Attiva 2FA',
  totpEnabledStatus: 'La 2FA è attiva.',
  totpSetupInstructions:
    'Scansiona il codice QR con la tua app di autenticazione (es. Google Authenticator, 1Password) e inserisci il codice mostrato.',
  totpQrAlt: 'Codice QR per l’app di autenticazione',
  totpManualEntryHint: 'Oppure inseriscilo manualmente:',
  totpCodeLabel: 'Codice',
  totpConfirmCta: 'Conferma',
  totpConfirming: 'Verifica in corso…',
  totpCancelCta: 'Annulla',
  totpConfirmError: 'Il codice non è valido o è scaduto.',
  totpGenericError: 'Impossibile avviare la configurazione della 2FA.',
  totpRegenerateCta: 'Rigenera codici di backup',
  totpDisableCta: 'Disattiva 2FA',
  totpDisableConfirmPrompt: 'Conferma la disattivazione con la tua password o un codice 2FA.',
  totpRegenerateConfirmPrompt:
    'Conferma la rigenerazione dei codici di backup con la tua password o un codice 2FA.',
  totpConfirmationFailedError: 'Conferma non riuscita.',
  useCurrentPasswordInstead: 'Usa invece la password attuale',
  useCodeInsteadOfPassword: 'Usa invece un codice 2FA',
  backupCodesTitle: 'Codici di backup',
  backupCodesSetupHint:
    'Questi 10 codici vengono mostrati solo ora. Conservali in un posto sicuro — ognuno funziona una sola volta se perdi l’accesso alla tua app di autenticazione.',
  backupCodesRegenerateHint:
    'I vecchi codici di backup non sono più validi. Conserva questi nuovi codici in un posto sicuro.',
  backupCodesAckCta: 'Codici salvati',
  myDataHeading: 'I tuoi dati',
  myDataBody:
    'Scarica tutto ciò che Votepit conserva su di te — profilo, idee, commenti, voti, reazioni, preferenze di notifica e servizi di accesso collegati, su tutti gli account (JSON).',
  myDataDownload: 'Scarica i miei dati',
  myDataDownloading: 'Preparazione…',
  myDataDownloadFailed: 'Il download non è riuscito. Riprova.',
  deleteMeHeading: 'Elimina account',
  deleteMeBody:
    'Elimina definitivamente il tuo account Votepit dopo 48 ore. Le tue idee e i tuoi commenti restano visibili come «Utente eliminato», i tuoi voti continuano a contare in forma anonima — tutti i dati personali vengono cancellati.',
  deleteMeButton: 'Elimina il mio account',
  deleteMeOwnerBlocked:
    'Sei ancora proprietario di un account. Eliminalo prima nelle sue impostazioni (oppure contatta il supporto per trasferirlo).',
  deleteMeConfirmLabel: 'Per confermare, inserisci il tuo indirizzo email',
  deleteMeConfirmSubmit: 'Conferma eliminazione',
  deleteMeSubmitting: 'Pianificazione…',
  deleteMeCancel: 'Annulla',
  deleteMeFailed: 'Non è stato possibile pianificare l’eliminazione.',
  deleteMePendingTitle: 'Eliminazione pianificata',
  deleteMePendingBody:
    'Il tuo account verrà eliminato definitivamente il {date}. Fino ad allora puoi annullare l’eliminazione.',
  deleteMeUndo: 'Annulla eliminazione',
  deleteMeUndoing: 'Annullamento…',
  deleteMeUndoFailed: 'Non è stato possibile annullare l’eliminazione.',
} satisfies typeof de

export default it
