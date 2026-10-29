// machine-drafted, needs native review
import type de from './moderationQueuePage.de'

const fr = {
  loading: 'Chargement…',
  loadError: "La page n'a pas pu être chargée.",
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody:
    'Cette page est réservée aux propriétaires, administrateurs et modérateurs de ce compte.',
  notFoundTitle: 'Tableau introuvable',
  notFoundBody: "Ce tableau n'existe pas dans ce compte.",
  title: "File d'approbation",
  subtitle:
    "Examinez les nouvelles idées et approuvez-les avant qu'elles n'apparaissent sur le tableau.",
  switchedOffHint:
    "L'approbation est désactivée pour ce tableau. Les nouvelles idées apparaissent immédiatement ; vous pouvez toujours traiter les idées encore en attente ici.",
  heading: 'Idées en attente ({count})',
  emptyState: "Aucune idée n'attend d'approbation.",
  selectAll: 'Tout sélectionner',
  selectIdea: 'Sélectionner « {title} »',
  approveSelected: 'Approuver la sélection ({count})',
  rejectSelected: 'Refuser la sélection ({count})',
  columnSelect: 'Sélection',
  columnIdea: 'Idée',
  columnSubmitted: 'Soumise',
  columnActions: 'Actions',
  approve: 'Approuver',
  reject: 'Refuser',
  approveFailed: "L'approbation a échoué. Veuillez réessayer.",
  approvedNotice: '{count} idée(s) approuvée(s).',
  rejectedNotice: '{count} idée(s) refusée(s).',
  guestLabel: 'Invité',
  rejectAllGuest: 'Refuser toutes les idées d’invités ({count})',
  rejectAllGuestTitle: 'Refuser {count} idée(s) d’invités ?',
  rejectAllGuestBody:
    'Toutes les idées en attente soumises sans compte sont refusées pour non-respect des règles du board. Les idées des membres ne sont pas concernées.',
  rejectAllGuestConfirm: 'Tout refuser',
  guestRejectedNotice: '{count} idée(s) d’invités refusée(s).',
  guestRejectFailed: 'Le refus a échoué. Veuillez réessayer.',
  approveAndTrust: 'Approuver et faire confiance à l’auteur',
  approvedAndTrustedNotice:
    'Idée approuvée. Les nouvelles idées de cette personne apparaissent désormais immédiatement.',
  approveAndTrustFailed: 'L’approbation avec confiance a échoué. Veuillez réessayer.',
  trustedAuthorsLink: 'Auteurs de confiance',
} satisfies typeof de

export default fr
