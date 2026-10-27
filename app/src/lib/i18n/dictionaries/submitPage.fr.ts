// machine-drafted, needs native review
import type de from './submitPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: "Le formulaire n'a pas pu être chargé pour le moment. Veuillez réessayer.",
  memberSuspendedError: 'Vous êtes actuellement suspendu et ne pouvez rien proposer.',
  backToList: 'Retour au tableau',
  heading: 'Proposer une nouvelle idée',
  subtitle: 'Un titre clair et un court paragraphe — texte brut, sans mise en forme.',
  honeypotLabel: 'Site web',
  titleLabel: 'Titre',
  titlePlaceholder: 'Titre court et concis',
  titleHint: '{count}/200 caractères',
  duplicateHintQuestion: 'Cette idée existe-t-elle déjà ?',
  duplicateHintLead: "Soutenir une idée existante a plus de poids que d'en ajouter une seconde.",
  votesCount: '{count} votes',
  votesCountSingular: '{count} vote',
  submitAnywayButton: 'Proposer quand même comme nouvelle idée',
  bodyLabel: 'Description',
  bodyPlaceholder: "De quoi s'agit-il ? Que faudrait-il changer ?",
  bodyHint: '{count}/500 caractères (min. 10)',
  submitting: 'Envoi…',
  submit: "Proposer l'idée",
  tagsSuggested: 'Suggéré',
  tagsLabel: 'Étiquettes (facultatif)',
  tagsHint:
    'Choisis jusqu’à {count} étiquettes existantes. Les nouvelles étiquettes sont créées par la modération.',
  tagsLimitReached:
    'Tu as atteint le nombre maximal d’étiquettes. Retires-en une pour en choisir une autre.',
  duplicateAiMatch: 'Correspondance IA',
  guestNotice:
    'Vous soumettez sans compte. Votre idée apparaît comme « Invité ». Les liens ne sont pas autorisés et vous ne recevrez pas de notifications. Vous ne retrouverez vos idées que sur cet appareil – pour cela, nous déposons un cookie après la soumission.',
  guestThanksPendingTitle: 'Merci pour votre idée !',
  guestThanksPendingBody:
    'Votre idée attend la validation de la modération. Tant qu’elle attend, vous pouvez la retirer ci-dessous sur cet appareil. Si elle est refusée, vous y verrez aussi le motif.',
  guestThanksPublishedTitle: 'Merci pour votre idée !',
  guestThanksPublishedBody:
    'Votre idée est maintenant visible. Sans compte, vous ne pouvez plus la modifier ni la retirer.',
  guestSubmitAnother: 'Soumettre une autre idée',
  guestMineHeading: 'Mes idées sur cet appareil',
  guestMineLead: 'Les idées que vous avez soumises sans compte dans ce navigateur.',
  guestStatePending: 'En attente de validation',
  guestStatePublished: 'Publiée',
  guestStateRejected: 'Refusée',
  guestRejectedReason: 'Motif : {reason}',
  guestWithdraw: 'Retirer',
  guestWithdrawFailed: 'Le retrait a échoué. Veuillez réessayer.',
  guestErrorUrl: 'Les liens ne sont pas autorisés sans compte. Veuillez les supprimer.',
  guestErrorCaptcha: 'La vérification anti-robot a échoué. Veuillez réessayer.',
  guestErrorUnavailable: 'La soumission sans compte n’est pas possible pour le moment.',
  guestErrorQueueFull:
    'Trop d’idées attendent actuellement une validation. Veuillez réessayer plus tard.',
  guestErrorRateLimited: 'Trop de soumissions. Veuillez patienter un instant puis réessayer.',
  guestErrorBlocked: 'Votre texte contient des termes non autorisés. Veuillez le reformuler.',
  guestErrorRejected: 'La soumission a été refusée. Veuillez recharger la page et réessayer.',
  tagSuggestionsPlanHint: "Les suggestions de tags par IA font partie de l'offre Pro.",
} satisfies typeof de

export default fr
