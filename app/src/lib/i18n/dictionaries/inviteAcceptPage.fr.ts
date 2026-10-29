// machine-drafted, needs native review
import type de from './inviteAcceptPage.de'

const fr = {
  invalidTitle: "Ce lien d'invitation n'est plus valide",
  invalidBody:
    'Le lien est invalide, a expiré ou a déjà été utilisé. Demandez à la personne qui vous a invité·e un nouveau lien.',
  acceptFailedFallback: "L'invitation n'a pas pu être acceptée. Réessayez dans un instant.",
  accepting: "Acceptation de l'invitation…",
  doneTitle: "Bienvenue dans l'équipe",
  doneBodyOwner: 'Vous êtes désormais propriétaire de ce compte.',
  doneBodyAdmin: 'Vous êtes désormais administrateur de ce compte.',
  doneBodyModerator: 'Vous êtes désormais modérateur de ce compte.',
  doneBodyMember: 'Vous avez désormais accès aux tableaux privés de ce compte.',
  goToMembers: 'Aller à la vue des membres',
  goToBoard: 'Aller au tableau',
  errorTitle: 'Momentanément injoignable',
  teamLimitTitle: "L'équipe est complète",
  teamLimitBody:
    'Ce compte a atteint la limite de membres de son forfait. Veuillez contacter la personne qui vous a invité – votre lien reste valide.',
  loginCta: 'Aller à la connexion',
  retryCta: 'Réessayer',
  mismatchTitle: 'Mauvais compte connecté',
  mismatchBody:
    "Cette invitation est destinée à une autre adresse e-mail. Connectez-vous avec le compte invité pour l'accepter.",
  mismatchCurrentAccount: 'Actuellement connecté en tant que : {publicId}',
  mismatchAliasHint:
    "Si vous avez bien reçu cet e-mail vous-même : un transfert ou une boîte alias compte comme une adresse différente de celle à laquelle l'invitation a été envoyée.",
  switchAccountCta: 'Se déconnecter et changer de compte',
  switchingAccount: 'Déconnexion…',
} satisfies typeof de

export default fr
