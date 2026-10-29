// machine-drafted, needs native review
import type de from './trustedAuthorsPage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Impossibile caricare la pagina.',
  accessDeniedTitle: 'Nessun accesso',
  accessDeniedBody:
    'Questa pagina è accessibile solo a proprietari, amministratori e moderatori di questo account.',
  title: 'Autori fidati',
  subtitle:
    'Le idee di queste persone compaiono subito nelle bacheche con approvazione, senza coda. Vale per tutte le bacheche di questo account.',
  heading: 'Fidati ({count})',
  emptyState:
    'Nessuno è ancora contrassegnato come fidato. Usa «Approva e considera affidabile l’autore» nella coda di approvazione.',
  columnAuthor: 'Autore',
  columnTrustedSince: 'Fidato dal',
  columnActions: 'Azioni',
  userLabel: 'Utente #{id}',
  userLabelWithUsername: 'Utente #{id} ({username})',
  revoke: 'Revoca fiducia',
  revokeAriaLabel: 'Revoca la fiducia per l’utente #{id}',
  revokeTitle: 'Revocare la fiducia?',
  revokeBody:
    'Le nuove idee di questa persona torneranno ad attendere l’approvazione. Le idee già pubblicate restano visibili.',
  revokeConfirm: 'Revoca',
  revokedNotice: 'Fiducia revocata.',
  revokeFailed: 'Revoca non riuscita. Riprova.',
  autoRevokeHint:
    'Se una persona riceve un avvertimento o una sospensione, la fiducia termina automaticamente.',
} satisfies typeof de

export default it
