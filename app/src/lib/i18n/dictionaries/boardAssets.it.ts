import type de from './boardAssets.de'

const it = {
  heading: 'Immagini e link',
  subtitle:
    'Copertina, icona e immagine di anteprima per i link, più un link di ritorno e una nota a piè di pagina per la pagina della tua board.',
  coverHeading: 'Immagine di copertina',
  coverHint:
    "JPEG, PNG o WebP, fino a 4 MB. Consigliato: 2400 × 400 px (6:1). Metti i contenuti importanti al centro: sugli schermi larghi si vede solo la parte centrale dell'immagine.",
  faviconHeading: 'Icona della scheda del browser',
  faviconHint:
    'Quadrata, almeno 180 × 180 px. Usata per la scheda del browser e l’icona della schermata Home.',
  ogHeading: 'Immagine di anteprima alla condivisione',
  ogHint: 'Mostrata quando la board viene condivisa. Ritagliata in 1200 × 630 px.',
  chooseFile: 'Scegli immagine',
  remove: 'Rimuovi',
  uploading: 'Caricamento…',
  dropChoose: 'Scegli un’immagine',
  dropReplace: 'Scegli un’altra immagine',
  dropOr: 'oppure trascinala qui: viene salvata subito',
  dropRelease: 'Rilascia per caricare',
  uploaded: 'Immagine salvata.',
  removed: 'Immagine rimossa.',
  notSet: 'Nessuna immagine impostata.',
  previewAlt: "Anteprima dell'immagine",
  lockedImages: 'Le immagini personalizzate non sono incluse nel tuo piano attuale.',
  uploadFailed: "Impossibile salvare l'immagine.",
  invalidUpload: 'Non è un’immagine valida. Sono consentiti JPEG, PNG e WebP.',
  fileTooLarge: 'Il file è troppo grande.',
  imageTooSmall: 'L’immagine è troppo piccola. Almeno {size} × {size} px.',
  removeFailed: "Impossibile rimuovere l'immagine.",
  linkHeading: 'Link di ritorno e piè di pagina',
  linkUrlLabel: 'Link al tuo sito web',
  linkUrlHint: 'Dove porta il link di ritorno, ad es. https://esempio.org. Inizia con https://.',
  linkLabelLabel: 'Testo del link di ritorno',
  linkLabelHint:
    'Mostrato in fondo al tuo board, ad es. «Torna a esempio.org». Da 1 a 40 caratteri.',
  footerLabel: 'Nota a piè di pagina',
  footerHint:
    'Breve testo in fondo al board, ad es. «Associazione Esempio». Solo testo, fino a 280 caratteri.',
  linkSave: 'Salva',
  linkSaved: 'Link di ritorno e piè di pagina salvati.',
  linkSaveFailed: 'Impossibile salvare i dati.',
  linkLocked: 'Il link di ritorno e il piè di pagina non sono inclusi nel tuo piano attuale.',
  planUpgradeLinkLabel: 'Vedi i piani',
  logoUnsupported: 'Sono consentiti JPEG, PNG e WebP. I file SVG non sono ammessi per i loghi.',
  logoTooSmall: 'Il logo è troppo piccolo. Almeno 200 × 200 px.',
  logoTooLarge: 'Le dimensioni dell’immagine sono troppo grandi.',
  logoHint:
    'JPEG, PNG o WebP, di almeno 200 × 200 px. Adattato come quadrato: foto profilo e icona della scheda. La trasparenza resta.',
  logoEmpty: 'Nessun simbolo dell’app ancora. Senza, la foto profilo mostra le iniziali del board.',
  wordmarkHint:
    'JPEG, PNG o WebP, di almeno 200 px per lato, es. 1200 × 300 px. Logo largo per la barra superiore; la trasparenza resta.',
  wordmarkEmpty:
    'Nessun wordmark ancora. La barra superiore mostra allora il simbolo dell’app o le iniziali.',
  platedTip:
    'Sul colore della tua barra superiore il logo si vedrebbe a malapena, quindi dietro viene messa automaticamente una superficie chiara. Per barre scure, carica sotto una variante chiara.',
  wordmarkLightHint:
    'Per barre scure. Usata solo se si legge bene sulla barra; altrimenti resta la wordmark sopra.',
  wordmarkLightEmpty: 'Nessuna variante chiara. Su barre scure vale la wordmark normale.',
  logoPendingAlt: 'Anteprima del logo selezionato',
  logoLegacyHeading: 'Logo precedente (link)',
  logoLegacyHint:
    'Questo link viene mostrato solo finché non è caricato un logo. Carica un’immagine per sostituirlo.',
  logoLegacyRemove: 'Rimuovi link',
  patternHeading: 'Motivo della copertina',
  patternHint: 'Mostrato finché non è caricata un’immagine di copertina propria.',
  patternDefault: 'Predefinito',
  pattern_waves: 'Onde',
  pattern_dots: 'Punti',
  pattern_stripes: 'Righe',
  pattern_bokeh: 'Bokeh',
  pattern_grid: 'Griglia',
  uploadTermsTitle: 'Condizioni per immagini proprie',
  uploadTermsIntro:
    "Prima di caricare un'immagine per la tua board, leggi questi punti. Li confermi una volta per il tuo account; chiederemo di nuovo solo se le condizioni cambiano.",
  uploadTermsPoint1:
    "Puoi caricare solo immagini per le quali hai tutti i diritti necessari: immagini proprie o immagini per cui hai una licenza d'uso. Marchi e loghi di terzi solo con il permesso dei titolari dei diritti.",
  uploadTermsPoint2:
    'Non sono ammessi contenuti illeciti. Le persone riconoscibili possono essere mostrate solo con il loro consenso, e i dati personali di altri solo se esiste una base giuridica.',
  uploadTermsPoint3:
    "Controlliamo formato e dimensioni, ricodifichiamo l'immagine e rimuoviamo dati accessori, come le informazioni sulla posizione. Sono accettati PNG, JPEG e WebP.",
  uploadTermsPoint4:
    "L'immagine è mostrata pubblicamente sulla pagina della tua board, anche nelle anteprime quando qualcuno condivide il link. Se la sostituisci o la rimuovi, il file precedente viene cancellato. Se cancelli la board o l'account, anche le immagini vengono cancellate.",
  uploadTermsPoint5:
    "Sei responsabile delle tue immagini e sollevi Votepit da pretese di terzi che si basano su di esse. Se un'immagine è palesemente illecita o arriva una segnalazione fondata, possiamo rimuoverla e te lo comunicheremo.",
  uploadTermsPoint6:
    "Come prova conserviamo solo l'orario, la versione di queste condizioni, il tuo account e il tuo identificativo utente pseudonimo. Né indirizzo e-mail né indirizzo IP.",
  uploadTermsCheckbox: 'Ho letto le condizioni per le mie immagini e le accetto.',
  uploadTermsAccept: 'Accetta e carica',
  uploadTermsCancel: 'Annulla',
  uploadTermsRequired: 'Accetta prima le condizioni per le tue immagini.',
  uploadTermsFailed: 'Non è stato possibile salvare la tua accettazione. Riprova.',
} satisfies typeof de

export default it
