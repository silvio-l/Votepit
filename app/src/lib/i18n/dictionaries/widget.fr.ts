// machine-drafted, needs native review
import type de from './widget.de'

const fr = {
  title: 'Feedback',
  close: 'Fermer',
  loading: 'Chargement…',
  loadFailed: "Le tableau n'a pas pu être chargé.",
  empty: "Pas encore d'idées.",
  upvote: 'Voter pour',
  downvote: 'Voter contre',
  comments: '{count} commentaires',
  commentSingular: '{count} commentaire',
  fewVotes: 'Encore peu de votes – le résultat peut encore beaucoup changer',
  approval: '{value}\u00a0% d’approbation',
  back: 'Retour',
  readOnlyHint: "Pour voter et commenter, le tableau s'ouvre dans un nouvel onglet.",
  openBoard: 'Ouvrir le tableau',
  openIdea: 'Ouvrir dans le tableau',
  commentLabel: 'Commentaire',
  commentSubmit: 'Commenter',
  newIdea: 'Nouvelle idée',
  titleLabel: 'Titre',
  bodyLabel: 'Description',
  submit: "Soumettre l'idée",
  submitted: 'Merci ! Votre idée a été soumise.',
  submittedPending: "Merci ! Votre idée apparaîtra dès que l'équipe l'aura approuvée.",
  actionFailed: "Cela n'a pas fonctionné. Veuillez réessayer.",
  verificationOnBoard:
    "Merci de confirmer que vous n'êtes pas un robot. Cela n'est possible que sur le tableau.",
  continueOnBoard: 'Continuer sur le tableau',
  signedInAs: 'Connecté en tant que {name}',
} satisfies typeof de

export default fr
