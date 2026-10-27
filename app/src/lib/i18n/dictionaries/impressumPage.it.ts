// machine-drafted, needs native review
import type de from './impressumPage.de'

const it = {
  loading: 'Caricamento…',
  errorHeading: 'Impossibile caricare questa pagina',
  errorLoadFailed: 'Riprova tra un momento.',
  notFoundTitle: 'Queste note legali non sono qui al momento',
  notFoundDescription:
    'Potrebbero essere state spostate o rimosse. Dalla home page si può proseguire.',
  retry: 'Riprova',
  heading: 'Note legali',
  subtitle: 'Note legali di {name}, come fornite dal suo gestore.',
  nameLabel: 'Gestore',
  addressLabel: 'Indirizzo',
  emailLabel: 'Contatto',
  noticeHeading: 'Informazioni aggiuntive',
  missingTitle: 'Nessuna nota legale disponibile',
  missingBody: 'Il gestore di questa bacheca non ha ancora fornito i dati per le note legali.',
  privacyHeading: 'Protezione dei dati (GDPR)',
  privacyBody:
    'Questa bacheca funziona su Votepit. Votepit tratta i dati per conto del gestore sopra indicato, che è il titolare del trattamento ("Verantwortlicher") responsabile di questa bacheca ai sensi del GDPR. Per i dettagli su come Votepit stessa tratta i dati in quanto piattaforma, consulta',
  privacyLinkLabel: 'l’informativa sulla privacy di Votepit',
} satisfies typeof de

export default it
