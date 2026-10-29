// machine-drafted, needs native review
import type de from './moderationQueuePage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Impossibile caricare la pagina.',
  accessDeniedTitle: 'Accesso negato',
  accessDeniedBody:
    'Questa pagina è accessibile solo a proprietari, amministratori e moderatori di questo account.',
  notFoundTitle: 'Bacheca non trovata',
  notFoundBody: 'Questa bacheca non esiste in questo account.',
  title: 'Coda di approvazione',
  subtitle: 'Esamina le nuove idee e approvale prima che appaiano sulla bacheca.',
  switchedOffHint:
    "L'approvazione è disattivata per questa bacheca. Le nuove idee appaiono subito; puoi comunque gestire le idee ancora in attesa qui.",
  heading: 'Idee in attesa ({count})',
  emptyState: 'Nessuna idea in attesa di approvazione.',
  selectAll: 'Seleziona tutto',
  selectIdea: 'Seleziona «{title}»',
  approveSelected: 'Approva selezione ({count})',
  rejectSelected: 'Rifiuta selezione ({count})',
  columnSelect: 'Selezione',
  columnIdea: 'Idea',
  columnSubmitted: 'Inviata',
  columnActions: 'Azioni',
  approve: 'Approva',
  reject: 'Rifiuta',
  approveFailed: 'Approvazione non riuscita. Riprova.',
  approvedNotice: '{count} idea/e approvata/e.',
  rejectedNotice: '{count} idea/e rifiutata/e.',
  guestLabel: 'Ospite',
  rejectAllGuest: 'Rifiuta tutte le idee degli ospiti ({count})',
  rejectAllGuestTitle: 'Rifiutare {count} idea/e degli ospiti?',
  rejectAllGuestBody:
    'Tutte le idee in attesa inviate senza account vengono rifiutate per violazione delle regole della bacheca. Le idee dei membri non vengono toccate.',
  rejectAllGuestConfirm: 'Rifiuta tutte',
  guestRejectedNotice: '{count} idea/e degli ospiti rifiutata/e.',
  guestRejectFailed: 'Il rifiuto non è riuscito. Riprova.',
  approveAndTrust: 'Approva e considera affidabile l’autore',
  approvedAndTrustedNotice: 'Idea approvata. Le nuove idee di questa persona ora compaiono subito.',
  approveAndTrustFailed: 'Approvazione con fiducia non riuscita. Riprova.',
  trustedAuthorsLink: 'Autori fidati',
} satisfies typeof de

export default it
