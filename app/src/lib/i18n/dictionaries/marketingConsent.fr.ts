// machine-drafted, needs native review
import type de from './marketingConsent.de'

const fr = {
  profileHeading: 'Actualités produit et offres',
  profileHint:
    'Des e-mails occasionnels sur les nouvelles fonctionnalités et offres de Votepit. Volontaire et révocable à tout moment. La confirmation est envoyée à ton adresse e-mail de notification confirmée.',
  toggleLabel: 'Recevoir les actualités produit et offres par e-mail',
  statusNone: 'Non activé.',
  statusPending:
    'Confirmation en attente. Nous t’avons envoyé un lien de confirmation par e-mail. Le lien est valable 48 heures.',
  statusConfirmed: 'Actif. Tu peux te désabonner à tout moment.',
  statusWithdrawn: 'Non activé.',
  needsNotificationEmail:
    "Confirme d'abord une adresse e-mail de notification pour que nous puissions t'y envoyer la confirmation.",
  saveError: "Le réglage n'a pas pu être enregistré. Réessaie.",
  loadError: "Ton réglage n'a pas pu être chargé.",
  signupCheckboxLabel:
    'Je souhaite recevoir les actualités produit et offres de Votepit par e-mail (facultatif, révocable à tout moment).',
  signupNotice:
    "Nous utilisons ton adresse e-mail pour des recommandations de produits propres et similaires de Votepit. Tu peux t'y opposer à tout moment ; les seuls frais sont ceux de transmission aux tarifs de base.",
  confirming: 'Confirmation en cours…',
  doneTitle: 'Actualités produit confirmées',
  doneBody:
    'Merci. Tu recevras désormais les actualités produit et offres de Votepit par e-mail. Tu peux le révoquer à tout moment dans les réglages de ton profil.',
  errorTitle: 'Confirmation impossible',
  invalidOrExpired:
    'Le lien est invalide ou a expiré. Demande une nouvelle confirmation dans les réglages de ton profil.',
  confirmFailedFallback: "La confirmation n'a pas pu aboutir. Réessaie plus tard.",
  backToProfile: 'Retour au profil',
} satisfies typeof de

export default fr
