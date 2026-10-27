// machine-drafted, needs native review
import type de from './boardsAdminPage.de'

const fr = {
  loading: 'Chargement…',
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody: 'Cette page est réservée aux administrateurs du compte.',
  loadError: "La page n'a pas pu être chargée.",
  title: 'Tableaux',
  subtitle: 'Tous les tableaux de votre compte.',
  noBoards: 'Aucun tableau créé pour le moment.',
  createFirstBoard: 'Créez votre premier tableau maintenant',
  boardsAriaLabel: 'Tableaux',
  boardsHeading: 'Tableaux ({count})',
  frozen: 'Gelé',
  complianceFrozenAccount: "Masqué — complétez les informations sur l'exploitant",
  complianceFrozenHintAccount:
    "Ce tableau est masqué car l'identité légale de l'exploitant au niveau du compte (nom, adresse et e-mail de contact) est incomplète. Cliquez pour la compléter sur la page Compte — cela dégèle tous les tableaux dont la déclaration d'exploitant a déjà été confirmée.",
  complianceFrozenBoard: "Masqué — confirmez la déclaration d'exploitant",
  complianceFrozenHintBoard:
    "Le nom légal de l'exploitant est défini au niveau du compte, mais ce tableau nécessite encore la confirmation de sa propre déclaration de responsabilité d'exploitant. Cliquez pour la confirmer ici.",
  manage: 'Paramètres',
  view: 'Voir',
  copyLink: 'Copier le lien du tableau',
  copyLinkCopied: 'Lien copié !',
  createHeading: 'Créer un nouveau tableau',
  nameLabel: 'Nom',
  namePlaceholder: 'ex. Nos idées',
  slugLabel: 'Slug',
  slugPlaceholder: 'ex. nos-idees',
  slugHint: 'Proposé à partir du nom, mais modifiable librement.',
  createSubmitting: 'Création…',
  createSubmit: 'Créer le tableau',
  newBoard: 'Nouveau tableau',
  statBoards: 'Tableaux',
  statIdeas: 'Idées',
  statVotes: 'Votes',
  statCaptionAll: 'sur tous les tableaux',
  ideasCount: '{count} idées',
  ideasCountSingular: '{count} idée',
  votesCount: '{count} votes',
  votesCountSingular: '{count} vote',
  createHint:
    'Un tableau est une page publique avec son propre lien, où les gens proposent des idées et votent.',
  emptyDescription:
    "Créez un tableau et partagez son lien — les idées et les votes s'y accumulent d'eux-mêmes.",
  operatorAckLabel:
    "Je confirme être l'exploitant légal de ce tableau — responsable de ses règles communautaires, de sa modération et de toutes les obligations de mentions légales (Impressum) et de confidentialité propres au tableau.",
} satisfies typeof de

export default fr
