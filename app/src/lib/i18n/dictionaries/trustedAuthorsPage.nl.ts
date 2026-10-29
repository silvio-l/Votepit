// machine-drafted, needs native review
import type de from './trustedAuthorsPage.de'

const nl = {
  loading: 'Laden…',
  loadError: 'De pagina kon niet worden geladen.',
  accessDeniedTitle: 'Geen toegang',
  accessDeniedBody:
    'Deze pagina is alleen toegankelijk voor eigenaren, beheerders en moderators van dit account.',
  title: 'Vertrouwde auteurs',
  subtitle:
    'Ideeën van deze personen verschijnen direct op borden met goedkeuring, zonder wachtrij. Dit geldt voor alle borden van dit account.',
  heading: 'Vertrouwd ({count})',
  emptyState:
    'Nog niemand is als vertrouwd gemarkeerd. Gebruik „Goedkeuren en auteur vertrouwen” in de goedkeuringswachtrij.',
  columnAuthor: 'Auteur',
  columnTrustedSince: 'Vertrouwd sinds',
  columnActions: 'Acties',
  userLabel: 'Gebruiker #{id}',
  userLabelWithUsername: 'Gebruiker #{id} ({username})',
  revoke: 'Vertrouwen intrekken',
  revokeAriaLabel: 'Vertrouwen voor gebruiker #{id} intrekken',
  revokeTitle: 'Vertrouwen intrekken?',
  revokeBody:
    'Nieuwe ideeën van deze persoon wachten daarna weer op goedkeuring. Al gepubliceerde ideeën blijven zichtbaar.',
  revokeConfirm: 'Intrekken',
  revokedNotice: 'Vertrouwen ingetrokken.',
  revokeFailed: 'Intrekken is mislukt. Probeer het opnieuw.',
  autoRevokeHint:
    'Krijgt iemand een waarschuwing of schorsing, dan vervalt het vertrouwen automatisch.',
} satisfies typeof de

export default nl
