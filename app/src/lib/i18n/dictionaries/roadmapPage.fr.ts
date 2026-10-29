// machine-drafted, needs native review
import type de from './roadmapPage.de'

const fr = {
  viewAriaLabel: 'Affichage',
  viewList: 'Liste',
  viewColumns: 'Colonnes',
  viewGantt: 'Gantt',
  ganttAriaLabel: 'Diagramme de Gantt',
  ganttEmptyTitle: 'Aucune date définie pour le moment',
  ganttEmptyDescription:
    "Dès qu'un administrateur du tableau définit des dates de début et d'échéance, les barres apparaissent ici.",
  ganttStartDateLabel: 'Début',
  ganttTargetDateLabel: 'Échéance',
  ganttNoDatesLabel: 'Sans dates',
  ganttOpenEndLabel: 'ouvert',
  ganttDatesUpdateError: "Les dates n'ont pas pu être enregistrées.",
  ganttInvalidDateRangeError: "Le début ne doit pas être postérieur à l'échéance.",
  ideaSingular: 'idée',
  ideaPlural: 'idées',
  ideasAriaLabel: 'Idées de la feuille de route',
  sectionOpen: 'Ouvert',
  sectionPlanned: 'Prévu',
  sectionInProgress: 'En cours',
  sectionDone: 'Terminé',
  sectionDeclined: 'Refusé',
  sectionArchived: 'Archivé',
  loading: 'Chargement…',
  errorNotFound: "Ce tableau n'existe pas — il a peut-être été renommé ou supprimé.",
  errorLoadFailed:
    "Nous n'avons pas pu charger la feuille de route pour le moment. Veuillez réessayer.",
  errorHeading: 'Erreur lors du chargement des données',
  retry: 'Réessayer',
  heading: 'Feuille de route',
  subtitle: "{name} — idées prévues, en cours et terminées en un coup d'œil.",
  emptyPlannedTitle: 'Rien de prévu pour le moment',
  emptyPlannedDescription: 'Les idées qui intègrent la feuille de route apparaissent ici.',
  emptyInProgressTitle: 'Rien en cours',
  emptyInProgressDescription: "Dès qu'une idée est en cours de réalisation, elle apparaît ici.",
  emptyDoneTitle: 'Rien de terminé pour le moment',
  emptyDoneDescription: 'Les idées réalisées sont regroupées ici.',
  machineTranslatedLabel: 'Traduction automatique',
  showOriginalLink: "Afficher l'original",
  showTranslationLink: 'Afficher la traduction',
} satisfies typeof de

export default fr
