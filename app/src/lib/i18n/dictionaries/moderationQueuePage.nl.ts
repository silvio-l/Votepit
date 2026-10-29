// machine-drafted, needs native review
import type de from './moderationQueuePage.de'

const nl = {
  loading: 'Laden…',
  loadError: 'De pagina kon niet worden geladen.',
  accessDeniedTitle: 'Geen toegang',
  accessDeniedBody:
    'Deze pagina is alleen toegankelijk voor eigenaren, beheerders en moderators van dit account.',
  notFoundTitle: 'Bord niet gevonden',
  notFoundBody: 'Dit bord bestaat niet in dit account.',
  title: 'Goedkeuringswachtrij',
  subtitle: 'Bekijk nieuwe ideeën en keur ze goed voordat ze op het bord verschijnen.',
  switchedOffHint:
    'Goedkeuring staat uit voor dit bord. Nieuwe ideeën verschijnen direct; de ideeën die hier nog wachten kun je nog steeds afhandelen.',
  heading: 'Wachtende ideeën ({count})',
  emptyState: 'Er wachten geen ideeën op goedkeuring.',
  selectAll: 'Alles selecteren',
  selectIdea: '“{title}” selecteren',
  approveSelected: 'Selectie goedkeuren ({count})',
  rejectSelected: 'Selectie afwijzen ({count})',
  columnSelect: 'Selectie',
  columnIdea: 'Idee',
  columnSubmitted: 'Ingediend',
  columnActions: 'Acties',
  approve: 'Goedkeuren',
  reject: 'Afwijzen',
  approveFailed: 'Goedkeuren is mislukt. Probeer het opnieuw.',
  approvedNotice: '{count} idee(ën) goedgekeurd.',
  rejectedNotice: '{count} idee(ën) afgewezen.',
  guestLabel: 'Gast',
  rejectAllGuest: 'Alle gastideeën afwijzen ({count})',
  rejectAllGuestTitle: '{count} gastidee(ën) afwijzen?',
  rejectAllGuestBody:
    'Alle wachtende ideeën die zonder account zijn ingediend, worden afgewezen wegens overtreding van de boardregels. Ideeën van leden blijven ongemoeid.',
  rejectAllGuestConfirm: 'Alles afwijzen',
  guestRejectedNotice: '{count} gastidee(ën) afgewezen.',
  guestRejectFailed: 'Afwijzen is niet gelukt. Probeer het opnieuw.',
  approveAndTrust: 'Goedkeuren en auteur vertrouwen',
  approvedAndTrustedNotice:
    'Idee goedgekeurd. Nieuwe ideeën van deze persoon verschijnen voortaan direct.',
  approveAndTrustFailed: 'Goedkeuren en vertrouwen is mislukt. Probeer het opnieuw.',
  trustedAuthorsLink: 'Vertrouwde auteurs',
} satisfies typeof de

export default nl
