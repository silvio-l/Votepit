// machine-drafted, needs native review
import type de from './editPage.de'

const fr = {
  forbiddenError: 'Seule la personne qui a proposé une idée peut la modifier.',
  notFoundError: "Cette idée n'existe plus — elle a peut-être été retirée.",
  editWindowExpiredTitle: 'Délai de modification expiré',
  editWindowExpiredError:
    'Les idées ne peuvent être modifiées que dans les deux heures suivant leur envoi. Ce délai est écoulé.',
  loadError: "Le formulaire n'a pas pu être chargé pour le moment. Veuillez réessayer.",
  genericError: "Une erreur s'est produite. Veuillez réessayer.",
  loading: 'Chargement…',
  backToIdea: "Retour à l'idée",
  backToBoard: 'Retour au tableau',
  heading: "Modifier l'idée",
  subheading: 'Un titre clair et un court paragraphe — texte brut, sans mise en forme.',
  websiteHoneypotLabel: 'Site web',
  titleLabel: 'Titre',
  titlePlaceholder: 'Titre court et concis',
  titleHint: '3–200 caractères',
  bodyLabel: 'Description',
  bodyPlaceholder: "De quoi s'agit-il ? Que faudrait-il changer ?",
  bodyHint: 'Au moins 10 caractères',
  saving: 'Enregistrement…',
  saveChanges: 'Enregistrer les modifications',
} satisfies typeof de

export default fr
