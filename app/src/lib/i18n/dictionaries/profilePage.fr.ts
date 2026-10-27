// machine-drafted, needs native review
import type de from './profilePage.de'

const fr = {
  loading: 'Chargement…',
  title: 'Profil',
  subtitle:
    'Votre photo, vos liens sociaux, vos comptes et la sécurité de connexion, réunis au même endroit.',
  accountsHeading: 'Vos comptes',
  noAccounts: "Vous n'êtes encore membre d'aucun compte.",
  noAccountsHint:
    "Vous pouvez tout de même voter partout et gérer votre profil — vous n'avez pas besoin de votre propre tableau pour cela.",
  noAccountsCta: 'Créer votre propre tableau',
  accountColumn: 'Compte',
  manageLink: 'Gérer',
  logoutCta: 'Se déconnecter',
  profileLoadError:
    'Impossible de charger votre photo, vos liens sociaux et votre paramètre de confidentialité.',
  profileLoadRetryCta: 'Réessayer',
  userIdHint: 'Votre ID utilisateur : n° {id}',
  loggedInHint:
    'Pour protéger votre vie privée, Votepit ne stocke jamais votre adresse e-mail en clair (uniquement une empreinte à sens unique) — elle ne peut donc pas être affichée ici.',

  // Username (optional public display name)
  usernameHeading: "Nom d'affichage",
  usernameHint:
    'Facultatif — affiché à la place de « Votant » sur vos idées et commentaires, mais uniquement tant que votre profil est public. Unique sur toute la plateforme.',
  usernameLabel: "Nom d'affichage",
  usernamePlaceholder: 'ex. mariedupont',
  usernameSave: 'Enregistrer',
  usernameSaving: 'Enregistrement…',
  usernameSaved: 'Enregistré.',
  usernameTakenError: "Ce nom d'affichage est déjà pris.",
  usernameInvalidError:
    'Utilisez 3 à 30 caractères, en commençant par une lettre (lettres, chiffres, tiret bas).',
  usernameGenericError: "Une erreur s'est produite. Veuillez réessayer.",

  // Avatar (profile-avatar-social)
  avatarHeading: 'Photo de profil',
  avatarHint: 'JPG, PNG, GIF ou WebP, 5 Mo max. Recadrée automatiquement en 256×256.',
  avatarUpload: 'Téléverser une photo',
  avatarUploading: 'Téléversement…',
  avatarRemove: 'Supprimer',
  avatarRemoving: 'Suppression…',
  avatarFileTooLarge: 'Le fichier dépasse 5 Mo.',
  avatarInvalidType:
    "Veuillez choisir une image (JPG, PNG, GIF ou WebP). Le format SVG n'est pas pris en charge.",
  avatarUploadError: "L'image n'a pas pu être traitée. Veuillez essayer une autre image.",
  avatarGenericError: "Une erreur s'est produite. Veuillez réessayer.",

  // Social links (profile-avatar-social security redesign)
  socialLinksHeading: 'Liens sociaux',
  socialLinksHint:
    "Jusqu'à 4 liens de profil prédéfinis — saisissez votre identifiant ou votre domaine, jamais une URL complète.",
  socialLinksWebsiteLabel: 'Site web',
  socialLinksXLabel: 'X (Twitter)',
  socialLinksYoutubeLabel: 'YouTube',
  socialLinksGithubLabel: 'GitHub',
  socialLinksHandlePlaceholder: 'identifiant',
  socialLinksUsernamePlaceholder: "nom d'utilisateur",
  socialLinksSave: 'Enregistrer',
  socialLinksSaving: 'Enregistrement…',
  socialLinksSaved: 'Enregistré.',
  socialLinksInvalidWebsite: 'Veuillez saisir un domaine seul, p. ex. example.com.',
  socialLinksInvalidXHandle:
    'Veuillez saisir un identifiant X valide (lettres, chiffres, tiret bas, 15 caractères max.).',
  socialLinksInvalidYoutubeHandle:
    'Veuillez saisir un identifiant YouTube valide sans « @ » (3 à 30 caractères).',
  socialLinksInvalidGithubUsername:
    "Veuillez saisir un nom d'utilisateur GitHub valide (39 caractères max.).",
  socialLinksSaveRejected: "L'une des valeurs a été refusée. Vérifiez le champ mis en évidence.",
  socialLinksGenericError: "Une erreur s'est produite. Veuillez réessayer.",

  // Linked accounts (OAuth login)
  oauthHeading: 'Comptes associés',
  oauthIntro: 'Comptes Google ou GitHub avec lesquels vous pouvez vous connecter.',
  oauthEmpty: 'Aucun compte associé.',
  oauthLinkedSince: 'Associé depuis le {date}',
  oauthUnlink: 'Dissocier',
  oauthUnlinkCancel: 'Annuler',
  oauthUnlinkFailed: 'La dissociation a échoué. Veuillez réessayer.',
  oauthUnlinkConfirmTitle: 'Dissocier ce compte ?',
  oauthUnlinkConfirmBody:
    'La connexion avec {provider} ne fonctionnera plus — vous pourrez toujours vous connecter avec votre adresse e-mail via un lien magique.',

  // Privacy (profile-visibility)
  privacyHeading: 'Confidentialité',
  privacyToggleLabel: 'Profil visible publiquement',
  privacyStateVisible:
    'Les autres voient votre photo de profil et vos liens sociaux sur vos idées et commentaires.',
  privacyStateAnonymous:
    'Vous apparaissez sur les idées et commentaires comme « Votant » — sans photo de profil ni liens sociaux.',
  privacyHint:
    'Par défaut, vous restez anonyme. Si vous rendez votre profil visible, les autres peuvent voir votre photo de profil et vos liens sociaux sur vos idées et commentaires, et ouvrir votre profil.',
  privacyRoleBadgeNote:
    "Indépendamment de cela : si vous êtes propriétaire ou modérateur d'un compte, ce rôle y est toujours affiché sous forme de badge.",
  privacySaved: 'Enregistré.',
  privacyGenericError: "Le paramètre n'a pas pu être enregistré. Veuillez réessayer.",

  // Notifications (notification-preferences)
  notificationsHeading: 'Notifications',
  notificationsHint:
    "Choisissez ce dont vous souhaitez être informé — dans l'application et par e-mail.",
  notificationsIdeaCommentLabel: 'Nouveau commentaire sur mon idée',
  notificationsThreadReplyLabel: "Nouvelle réponse dans une discussion que j'ai commentée",
  notificationsAbuseReportLabel: 'Nouveau signalement de contenu (opérateur de la plateforme)',
  notificationsSupportTicketLabel:
    "Ticket d'assistance nouveau ou répondu (opérateur de la plateforme)",
  notificationsWeeklyDigestLabel: 'Résumé hebdomadaire de mes tableaux',
  notificationsWeeklyDigestHint:
    'Une fois par semaine : idées nouvelles, populaires, les plus rejetées et les plus controversées. Activé par défaut pour les propriétaires ; les semaines sans activité sont ignorées.',
  notificationsInAppColumn: "Dans l'app",
  notificationsEmailColumn: 'E-mail',
  notificationsEmailFieldLabel: 'E-mail de notification',
  notificationsEmailPlaceholder: 'nom@example.com',
  notificationsEmailSubmit: 'Envoyer le lien de confirmation',
  notificationsEmailSubmitting: 'Envoi…',
  notificationsEmailSentHint:
    "E-mail de confirmation envoyé — veuillez cliquer sur le lien qu'il contient.",
  notificationsEmailInvalidError: 'Veuillez saisir une adresse e-mail valide.',
  notificationsEmailConfirmedPrefix: 'E-mail confirmé :',
  notificationsEmailRemove: "Supprimer l'e-mail",
  notificationsEmailRemoving: 'Suppression…',
  notificationsSaved: 'Enregistré.',
  notificationsGenericError: "Le paramètre n'a pas pu être enregistré. Veuillez réessayer.",

  // Password
  passwordHeading: 'Mot de passe',
  passwordSubtitle: 'Facultatif — en complément du lien de connexion par e-mail.',
  currentPasswordLabel: 'Mot de passe actuel',
  newPasswordLabel: 'Nouveau mot de passe',
  setPasswordLabel: 'Mot de passe',
  confirmPasswordLabel: 'Confirmer le mot de passe',
  passwordMinLengthHint: 'Au moins 10 caractères.',
  passwordMismatchError: 'Les mots de passe ne correspondent pas.',
  passwordSaveCta: 'Enregistrer',
  passwordSaving: 'Enregistrement…',
  passwordSavedSuccess: 'Mot de passe enregistré.',
  passwordGenericError: "Le mot de passe n'a pas pu être enregistré.",
  passwordResetLinkCta: "M'envoyer plutôt un lien de réinitialisation par e-mail",
  passwordResetLinkCancelCta: 'Annuler',
  passwordResetLinkEmailLabel: 'Confirmez votre e-mail',
  passwordResetLinkEmailHint:
    'Nous ne pouvons pas consulter votre adresse — veuillez la saisir à nouveau pour confirmer.',
  passwordResetLinkSendCta: 'Envoyer le lien de réinitialisation',
  passwordResetLinkSending: 'Envoi…',
  passwordResetLinkSuccess:
    'Consultez votre boîte de réception — un lien de réinitialisation est en route.',
  passwordResetLinkMismatchError: "Cela ne correspond pas à l'e-mail de ce compte.",
  passwordResetLinkGenericError:
    "Le lien de réinitialisation n'a pas pu être envoyé. Veuillez réessayer.",

  // 2FA
  totpHeading: 'Authentification à deux facteurs',
  totpSubtitle:
    "Facultatif — ajoute une protection supplémentaire avec une application d'authentification.",
  totpEnableCta: 'Activer la 2FA',
  totpEnabledStatus: 'La 2FA est active.',
  totpSetupInstructions:
    "Scannez le code QR avec votre application d'authentification (p. ex. Google Authenticator, 1Password) et saisissez le code affiché.",
  totpQrAlt: "Code QR pour l'application d'authentification",
  totpManualEntryHint: 'Ou saisissez manuellement :',
  totpCodeLabel: 'Code',
  totpConfirmCta: 'Confirmer',
  totpConfirming: 'Vérification…',
  totpCancelCta: 'Annuler',
  totpConfirmError: 'Le code est invalide ou a expiré.',
  totpGenericError: 'Impossible de lancer la configuration de la 2FA.',
  totpRegenerateCta: 'Régénérer les codes de secours',
  totpDisableCta: 'Désactiver la 2FA',
  totpDisableConfirmPrompt: 'Confirmez la désactivation avec votre mot de passe ou un code 2FA.',
  totpRegenerateConfirmPrompt:
    'Confirmez la régénération des codes de secours avec votre mot de passe ou un code 2FA.',
  totpConfirmationFailedError: 'La confirmation a échoué.',
  useCurrentPasswordInstead: 'Utiliser plutôt votre mot de passe actuel',
  useCodeInsteadOfPassword: 'Utiliser plutôt un code 2FA',
  backupCodesTitle: 'Codes de secours',
  backupCodesSetupHint:
    "Ces 10 codes ne sont affichés qu'une seule fois. Conservez-les en lieu sûr — chacun fonctionne une fois si vous perdez l'accès à votre application d'authentification.",
  backupCodesRegenerateHint:
    'Les anciens codes de secours ne sont plus valides. Conservez ces nouveaux codes en lieu sûr.',
  backupCodesAckCta: 'Codes enregistrés',
  myDataHeading: 'Vos données',
  myDataBody:
    'Téléchargez tout ce que Votepit conserve à votre sujet — profil, idées, commentaires, votes, réactions, préférences de notification et services de connexion liés, pour tous les comptes (JSON).',
  myDataDownload: 'Télécharger mes données',
  myDataDownloading: 'Préparation…',
  myDataDownloadFailed: 'Le téléchargement a échoué. Veuillez réessayer.',
  deleteMeHeading: 'Supprimer le compte',
  deleteMeBody:
    'Supprime définitivement votre compte Votepit après 48 heures. Vos idées et commentaires restent visibles sous « Utilisateur supprimé », vos votes continuent de compter anonymement — toutes les données personnelles sont effacées.',
  deleteMeButton: 'Supprimer mon compte',
  deleteMeOwnerBlocked:
    'Vous possédez encore un compte. Supprimez-le d’abord dans ses paramètres (ou contactez le support pour le transférer).',
  deleteMeConfirmLabel: 'Pour confirmer, saisissez votre adresse e-mail',
  deleteMeConfirmSubmit: 'Confirmer la suppression',
  deleteMeSubmitting: 'Planification…',
  deleteMeCancel: 'Annuler',
  deleteMeFailed: 'La suppression n’a pas pu être planifiée.',
  deleteMePendingTitle: 'Suppression planifiée',
  deleteMePendingBody:
    'Votre compte sera définitivement supprimé le {date}. D’ici là, vous pouvez annuler la suppression.',
  deleteMeUndo: 'Annuler la suppression',
  deleteMeUndoing: 'Annulation…',
  deleteMeUndoFailed: 'La suppression n’a pas pu être annulée.',
} satisfies typeof de

export default fr
