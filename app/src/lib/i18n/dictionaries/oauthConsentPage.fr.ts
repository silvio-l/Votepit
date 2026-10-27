// machine-drafted, needs native review
import type de from './oauthConsentPage.de'

const fr = {
  loading: 'Vérification de la demande…',
  redirecting: "Retour à l'application…",
  errorTitle: 'Demande invalide',
  invalidRequest:
    "Cette demande d'autorisation est invalide ou a expiré. Relancez la connexion dans votre application.",
  title: '{client} souhaite accéder à Votepit',
  intro:
    "L'application obtient l'accès aux tableaux que vous sélectionnez ici, chacun avec l'autorisation choisie. Elle agit en votre nom via l'API Agent.",
  redirectNotice: 'Après votre décision, vous serez renvoyé vers :',
  accountLabel: 'Compte',
  accountUnavailable: "{account} (API Agent non incluse dans l'offre actuelle)",
  boardsLegend: 'Tableaux et autorisation',
  levelLabel: 'Autorisation pour {board}',
  'level.read': 'Lecture',
  'level.write': 'Lecture et écriture',
  'level.moderate': 'Modération',
  noBoards: "Ce compte n'a pas encore de tableau.",
  noAccounts:
    "Vous ne pouvez accorder l'accès à aucun compte — il faut être propriétaire ou administrateur d'un compte.",
  planLimit: "L'API Agent n'est pas incluse dans l'offre actuelle de ce compte.",
  submitFailed: "Votre décision n'a pas pu être enregistrée. Veuillez réessayer.",
  approve: "Autoriser l'accès",
  deny: 'Refuser',
  revokeHint: "Vous pouvez révoquer l'accès à tout moment sous Admin → Jetons API.",
} satisfies typeof de

export default fr
