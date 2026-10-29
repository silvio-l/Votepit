// machine-drafted, needs native review
import type de from './supportPage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Impossibile caricare il supporto.',
  title: 'Supporto',
  subtitle:
    'Contatta il nostro team direttamente dalla dashboard — ti risponderemo il prima possibile.',
  accessDeniedTitle: 'Nessun accesso',
  accessDeniedBody: 'Non sei membro di questo account.',

  formHeading: 'Nuova richiesta',
  categoryLabel: 'Categoria',
  subjectLabel: 'Oggetto',
  messageLabel: 'Messaggio',
  submit: 'Invia',
  submitting: 'Invio…',
  submitSuccess:
    'Grazie! Abbiamo ricevuto la tua richiesta. Quando risponderemo, troverai la risposta nella tua posta in arrivo.',
  submitFailed: 'Impossibile inviare la richiesta. Riprova.',

  faqDeflectionHeading: 'Questo potrebbe già esserti utile',

  ticketsHeading: 'Le tue richieste ({count})',
  noTickets: 'Non hai ancora inviato nessuna richiesta di supporto.',
  subjectColumn: 'Oggetto',
  categoryColumn: 'Categoria',
  statusColumn: 'Stato',
  updatedColumn: 'Ultima attività',
  ticketNotFoundTitle: 'Ticket non trovato',
  ticketNotFoundBody:
    'Questa richiesta di supporto non esiste (più) o non appartiene a questo account.',
  threadHeading: 'Conversazione',
  threadLoading: 'Caricamento della conversazione…',
  threadLoadError: 'Impossibile caricare la conversazione.',
  fromYou: 'Tu',
  fromSupport: 'Supporto',
  replyLabel: 'La tua risposta',
  replyPlaceholder: 'Scrivi un messaggio…',
  replySubmit: 'Invia',
  replySubmitting: 'Invio…',
  replyFailed: 'Impossibile inviare il messaggio. Riprova.',

  machineTranslatedLabel: 'Tradotto automaticamente',
  showOriginalLink: 'Mostra originale',
  showTranslationLink: 'Mostra traduzione',
  translationUnavailableHint: 'La traduzione non è al momento disponibile.',

  'category.billing': 'Fatturazione',
  'category.technical': 'Problema tecnico',
  'category.account': 'Account e iscrizione',
  'category.feature_request': 'Richiesta di funzionalità',
  'category.privacy': 'Privacy',
  'category.other': 'Altro',

  'status.open': 'Aperta',
  'status.answered': 'Con risposta',
  'status.closed': 'Chiusa',
} satisfies typeof de

export default it
