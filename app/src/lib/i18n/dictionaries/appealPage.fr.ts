// machine-drafted, needs native review
import type de from './appealPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: "La page n'a pas pu être chargée.",
  scopeLabel: 'Recours',
  notFoundError: 'Ce retrait est introuvable.',
  notAuthorError: "Seul l'auteur d'origine peut contester ce retrait.",
  alreadyPendingError: "Un recours contre ce retrait est déjà en cours d'examen.",
  submitFailed: "Le recours n'a pas pu être envoyé. Veuillez réessayer.",
  backToBoard: 'Retour au tableau',
  heading: 'Contester ce retrait',
  subtitle:
    'Si vous pensez que votre contenu a été retiré par erreur, expliquez pourquoi ici. Un modérateur examinera votre recours.',
  messageLabel: 'Pourquoi ce retrait devrait-il être reconsidéré ?',
  messagePlaceholder: 'Expliquez pourquoi vous contestez ce retrait…',
  messageHint: '{count} caractères',
  submit: 'Envoyer le recours',
  submitting: 'Envoi…',
  submittedTitle: 'Recours envoyé',
  submittedBody:
    "Merci, votre recours a été envoyé. Un modérateur va l'examiner et vous serez informé de la décision.",
} satisfies typeof de

export default fr
