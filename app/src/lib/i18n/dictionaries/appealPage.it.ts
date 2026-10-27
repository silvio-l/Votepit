// machine-drafted, needs native review
import type de from './appealPage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Impossibile caricare la pagina.',
  scopeLabel: 'Ricorso',
  notFoundError: 'Questa rimozione non è stata trovata.',
  notAuthorError: 'Solo l’autore originale può presentare ricorso contro questa rimozione.',
  alreadyPendingError: 'Un ricorso contro questa rimozione è già in attesa di revisione.',
  submitFailed: 'Impossibile inviare il ricorso. Riprova.',
  backToBoard: 'Torna alla bacheca',
  heading: 'Presenta ricorso contro questa rimozione',
  subtitle:
    'Se ritieni che il tuo contenuto sia stato rimosso per errore, spiega qui il motivo. Un moderatore esaminerà il tuo ricorso.',
  messageLabel: 'Perché questa rimozione dovrebbe essere riconsiderata?',
  messagePlaceholder: 'Descrivi perché non sei d’accordo con la rimozione…',
  messageHint: '{count} caratteri',
  submit: 'Invia ricorso',
  submitting: 'Invio…',
  submittedTitle: 'Ricorso inviato',
  submittedBody:
    'Grazie, il tuo ricorso è stato inviato. Un moderatore lo esaminerà e riceverai una notifica sull’esito.',
} satisfies typeof de

export default it
