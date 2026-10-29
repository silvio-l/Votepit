// machine-drafted, needs native review
import type de from './discoverPage.de'

const fr = {
  heading: 'Là où les votes font bouger les choses',
  subtitle:
    'Des tableaux Votepit publics où de vraies communautés votent sur de vraies idées. Parcourez, votez et découvrez ce que les gens veulent vraiment.',
  loading: 'Chargement…',
  errorHeading: 'Échec du chargement',
  errorLoadFailed:
    "Nous n'avons pas pu charger les tableaux publics pour le moment. Veuillez réessayer.",
  retry: 'Réessayer',
  emptyTitle: 'Aucun tableau public pour le moment',
  emptyDescription: "Dès qu'un tableau est défini comme « Public », il apparaît ici.",
  boardsAriaLabel: 'Tableaux publics',
  votesCount: '{count} votes',
  votesCountSingular: '{count} vote',
  ideasCount: '{count} idées',
  ideasCountSingular: '{count} idée',
  paginationAriaLabel: 'Pages',
  paginationPageOf: 'Page {page} sur {totalPages}',
  paginationPrev: 'Précédent',
  paginationNext: 'Suivant',
} satisfies typeof de

export default fr
