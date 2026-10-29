// machine-drafted, needs native review
import type de from './trustedAuthorsPage.de'

const fr = {
  loading: 'Chargement…',
  loadError: 'La page n’a pas pu être chargée.',
  accessDeniedTitle: 'Accès refusé',
  accessDeniedBody:
    'Cette page est réservée aux propriétaires, administrateurs et modérateurs de ce compte.',
  title: 'Auteurs de confiance',
  subtitle:
    'Les idées de ces personnes apparaissent immédiatement sur les tableaux avec approbation, sans file d’attente. Cela vaut pour tous les tableaux de ce compte.',
  heading: 'De confiance ({count})',
  emptyState:
    'Personne n’est encore marqué comme de confiance. Utilisez « Approuver et faire confiance à l’auteur » dans la file d’approbation.',
  columnAuthor: 'Auteur',
  columnTrustedSince: 'De confiance depuis',
  columnActions: 'Actions',
  userLabel: 'Utilisateur n° {id}',
  userLabelWithUsername: 'Utilisateur n° {id} ({username})',
  revoke: 'Retirer la confiance',
  revokeAriaLabel: 'Retirer la confiance à l’utilisateur n° {id}',
  revokeTitle: 'Retirer la confiance ?',
  revokeBody:
    'Les nouvelles idées de cette personne attendront à nouveau une approbation. Les idées déjà publiées restent visibles.',
  revokeConfirm: 'Retirer',
  revokedNotice: 'Confiance retirée.',
  revokeFailed: 'Le retrait a échoué. Veuillez réessayer.',
  autoRevokeHint:
    'Si une personne est avertie ou suspendue, la confiance prend fin automatiquement.',
} satisfies typeof de

export default fr
