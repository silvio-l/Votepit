// machine-drafted, needs native review
import type de from './appealsAdminPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: "La page n'a pas pu être chargée.",
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody:
    'Cette page est réservée aux propriétaires, administrateurs et modérateurs de ce compte.',
  resolveFailed: "Le recours n'a pas pu être traité. Veuillez réessayer.",
  title: 'Recours',
  subtitle: 'Examinez et tranchez les recours contre les décisions de modération.',
  appealsHeading: 'Recours ({count} en attente)',
  emptyState: "Il n'y a aucun recours.",
  columnContent: 'Contenu retiré',
  columnReason: 'Motif',
  columnMessage: 'Message du recours',
  columnStatus: 'Statut',
  columnSubmitted: 'Envoyé le',
  columnActions: 'Actions',
  statusPending: 'En attente',
  statusUpheld: 'Maintenu',
  statusOverturned: 'Annulé',
  overturn: 'Annuler la décision',
  uphold: 'Maintenir',
} satisfies typeof de

export default fr
