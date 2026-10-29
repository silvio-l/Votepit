// machine-drafted, needs native review
import type de from './editPage.de'

const it = {
  forbiddenError: 'Solo chi ha proposto un’idea può modificarla.',
  notFoundError: 'Questa idea non c’è più — potrebbe essere stata ritirata.',
  editWindowExpiredTitle: 'Tempo per la modifica scaduto',
  editWindowExpiredError:
    'Le idee possono essere modificate solo entro due ore dalla proposta. Questo intervallo è terminato.',
  loadError: 'Al momento non è stato possibile caricare il modulo. Riprova.',
  genericError: 'Qualcosa è andato storto. Riprova.',
  loading: 'Caricamento…',
  backToIdea: 'Torna all’idea',
  backToBoard: 'Torna alla bacheca',
  heading: 'Modifica idea',
  subheading: 'Un titolo chiaro e un breve paragrafo — testo semplice, senza formattazione.',
  websiteHoneypotLabel: 'Sito web',
  titleLabel: 'Titolo',
  titlePlaceholder: 'Titolo breve e conciso',
  titleHint: '3–200 caratteri',
  bodyLabel: 'Descrizione',
  bodyPlaceholder: 'Di cosa si tratta? Cosa dovrebbe cambiare?',
  bodyHint: 'Almeno 10 caratteri',
  saving: 'Salvataggio…',
  saveChanges: 'Salva modifiche',
} satisfies typeof de

export default it
