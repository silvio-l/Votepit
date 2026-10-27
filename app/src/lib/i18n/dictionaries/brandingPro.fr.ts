import type de from './brandingPro.de'

const fr = {
  heading: 'Personnalisation avancée (Pro)',
  subtitle:
    'Typographie, angles et densité de la page de ton board, tes propres termes, liens d’en-tête et l’interrupteur de la feuille de route.',
  presetsHeading: 'Typographie, angles et densité',
  typographyLabel: 'Typographie',
  shapeLabel: 'Angles',
  densityLabel: 'Densité',
  typoDefault: 'Standard Votepit',
  typoInter: 'Inter (calme, neutre)',
  typoMono: 'Mono (technique)',
  shapeRounded: 'Arrondi (standard)',
  shapeSoft: 'Doux',
  shapeSquare: 'Carré',
  densityComfortable: 'Confortable (standard)',
  densityCompact: 'Compacte',
  wordingHeading: 'Tes termes',
  wordingHint:
    'Laisse vide pour utiliser le texte de Votepit. Chaque langue n’utilise que ce que tu y saisis. Texte brut uniquement, sans < > { }.',
  wordingLocaleLabel: 'Langue',
  wordingIdeaSingular: 'Terme pour « idée » (singulier)',
  wordingIdeaPlural: 'Terme pour « idées » (pluriel)',
  wordingSubmitButton: 'Texte du bouton d’envoi',
  wordingEmptyState: 'Texte quand il n’y a pas encore d’idées',
  wordingThankYou: 'Message de remerciement après l’envoi',
  wordingVoteFor: 'Terme pour « Pour »',
  wordingVoteAgainst: 'Terme pour « Contre »',
  linksHeading: 'Liens d’en-tête',
  linksHint:
    'Jusqu’à 5 liens en haut de votre board, p. ex. vers vos règles ou votre accueil. Les adresses commencent par https://. Les lignes vides sont ignorées.',
  linkUrlLabel: 'Adresse du lien (https://…)',
  linkLabelLabel: 'Texte du lien',
  roadmapHiddenLabel: 'Masquer la feuille de route aux visiteurs',
  roadmapHiddenHint:
    'Les visiteurs ne voient alors plus le lien et reçoivent une 404. Toi et les admins la voyez toujours.',
  lockedHint: 'Non inclus dans ton forfait actuel. Les valeurs enregistrées sont conservées.',
  save: 'Enregistrer',
  saving: 'Enregistrement…',
  saved: 'Enregistré.',
  saveFailed: 'L’enregistrement a échoué. Réessaie.',
  validationError: 'Vérifie les champs signalés.',
  planLocked: 'Cette section n’est pas incluse dans ton forfait actuel.',
  loadFailed: 'Les réglages n’ont pas pu être chargés.',
  planUpgradeLinkLabel: 'Voir les forfaits',
} satisfies typeof de

export default fr
