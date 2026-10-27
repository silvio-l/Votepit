// machine-drafted, needs native review
import type de from './boardPage.de'

const fr = {
  timeAgoJustNow: "à l'instant",
  timeAgoMinutes: 'il y a {count} min',
  timeAgoHours: 'il y a {count} h',
  timeAgoDaySingular: 'il y a {count} jour',
  timeAgoDayPlural: 'il y a {count} jours',
  timeAgoMonthSingular: 'il y a {count} mois',
  timeAgoMonthPlural: 'il y a {count} mois',
  timeAgoYearSingular: 'il y a {count} an',
  timeAgoYearPlural: 'il y a {count} ans',
  loadError: "Nous n'avons pas pu charger les idées pour le moment. Veuillez réessayer.",
  loading: 'Chargement…',
  loadErrorTitle: "Les idées n'ont pas pu être chargées",
  participationHintAnonymousVoting:
    'Vous pouvez voter sans vous connecter. Pour proposer votre propre idée, connectez-vous une fois avec votre adresse e-mail.',
  newIdea: 'Nouvelle idée',
  noIdeasForStatusTitle: 'Aucune idée avec ce statut',
  noIdeasForStatusDescription:
    'Rien ne correspond à ce filtre pour le moment. Choisissez un autre statut ou affichez toutes les idées.',
  pinnedHeading: 'Épinglées',
  filtersButton: 'Filtres',
  weekApprovalLabel: 'Ø approbation',
  loadMore: 'Charger plus',
  loadMoreRemaining: 'reste {count}',
  tagsFilterLabel: 'Étiquettes',
  allTags: 'Toutes les étiquettes',
  clearAllFilters: 'Effacer tous les filtres',
  searchLabel: 'Rechercher des idées',
  searchPlaceholder: 'Rechercher des idées…',
  noSearchResultsTitle: 'Aucun résultat',
  noSearchResultsDescription:
    "Aucune idée ne correspond à « {query} ». Essayez d'autres mots — ou proposez-la comme nouvelle idée.",
  resetSearch: 'Effacer la recherche',
  noIdeasTitle: 'Aucune idée pour le moment',
  noIdeasDescription:
    "Rien n'a encore été proposé ici. Lancez-vous — la première idée fait démarrer le tableau.",
  submitFirstIdea: 'Proposer la première idée',
  ideasAriaLabel: 'Idées',
  poweredBy: 'Powered by Votepit',
  operatedBy: 'Exploité par {name}',
  legalNoticeLink: 'Mentions légales',
  milestoneFirstIdeaTitle: 'Votre première idée est arrivée !',
  milestoneFirstIdeaBody: 'Votre tableau prend vie — continuez ainsi.',
  milestoneFirstExternalTitle: "Votre première idée venue de l'extérieur !",
  milestoneFirstExternalBody:
    "Quelqu'un d'autre vient de participer — votre tableau grandit au-delà de vous.",
  milestoneTenIdeasTitle: '10 idées atteintes !',
  milestoneTenIdeasBody: "Votre tableau prend de l'élan.",
  machineTranslatedLabel: 'Traduction automatique',
  introShowMore: 'Afficher plus',
  introShowLess: 'Afficher moins',
  showOriginalLink: "Afficher l'original",
  showTranslationLink: 'Afficher la traduction',
  translationUnavailableHint: 'La traduction est actuellement indisponible.',
  participationHintGuestSubmission:
    'Vous pouvez soumettre une idée sans compte – elle apparaît alors comme « Invité ». Pour voter, vous vous connectez une fois avec votre adresse e-mail.',
  participationHintAnonymousBoth:
    'Voter et soumettre des idées fonctionne sans connexion. Les idées sans compte apparaissent comme « Invité ».',
  shareIdea: 'Partager une idée',
  shareBoard: 'Partager',
  shareCopied: 'Lien copié.',
  shareFailed: "Le partage n'a pas fonctionné. Copiez l'adresse depuis la barre d'adresse.",
  statIdeasSingular: '{count} idée',
  statIdeasPlural: '{count} idées',
  statVotesWeekSingular: '{count} vote cette semaine',
  statVotesWeekPlural: '{count} votes cette semaine',
  whatHappensNext: 'Que se passe-t-il ensuite ?',
} satisfies typeof de

export default fr
