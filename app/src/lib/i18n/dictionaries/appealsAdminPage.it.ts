// machine-drafted, needs native review
import type de from './appealsAdminPage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Impossibile caricare la pagina.',
  accessDeniedTitle: 'Nessun accesso',
  accessDeniedBody:
    'Questa pagina è accessibile solo a proprietari, amministratori e moderatori di questo account.',
  resolveFailed: 'Impossibile decidere il ricorso. Riprova.',
  title: 'Ricorsi',
  subtitle: 'Esamina e decidi i ricorsi contro le decisioni di moderazione.',
  appealsHeading: 'Ricorsi ({count} in sospeso)',
  emptyState: 'Non ci sono ricorsi.',
  columnContent: 'Contenuto rimosso',
  columnReason: 'Motivo',
  columnMessage: 'Messaggio del ricorso',
  columnStatus: 'Stato',
  columnSubmitted: 'Inviato',
  columnActions: 'Azioni',
  statusPending: 'In sospeso',
  statusUpheld: 'Confermata',
  statusOverturned: 'Annullata',
  overturn: 'Annulla rimozione',
  uphold: 'Conferma rimozione',
} satisfies typeof de

export default it
