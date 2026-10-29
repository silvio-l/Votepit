// machine-drafted, needs native review
import type de from './accountPage.de'

const it = {
  loading: 'Caricamento…',
  accessDeniedTitle: 'Nessun accesso',
  accessDeniedBody: 'Questa pagina è accessibile solo al proprietario dell’account.',
  loadError: 'Impossibile caricare la pagina.',
  title: 'Account',
  subtitle: 'Esportazione ed eliminazione dei dati di questo account.',
  accountHeading: 'Questo account',
  nameLabel: 'Nome',
  slugLabel: 'Indirizzo (nome breve)',
  slugHint:
    'Parte dell’indirizzo dell’account, ad es. «miclub» in …/miclub/… Solo lettere minuscole, cifre e trattini. Unico in tutto Votepit.',
  slugChecking: 'Verifica in corso…',
  slugAvailable: 'Questo indirizzo è disponibile.',
  slugTaken: 'Questo indirizzo è già in uso.',
  savingEllipsis: 'Salvataggio…',
  renameSubmit: 'Salva nome e indirizzo',
  renameSaved: 'Salvato.',
  saveFailedGeneric: 'Salvataggio non riuscito. Riprova.',
  legalOperatorHeading: 'Dati del gestore della bacheca',
  legalOperatorBody:
    'Mostrati nella tua pagina pubblica delle note legali (collegata da ogni bacheca pubblica). Nome, indirizzo ed e-mail di contatto sono tutti obbligatori prima che una bacheca possa diventare pubblica o non elencata.',
  legalOperatorNameLabel: 'Nome del gestore',
  legalOperatorNameHint: 'Il nome della tua azienda, oppure il tuo nome se operi da solo.',
  legalOperatorNamePlaceholder: 'es. Acme S.r.l., oppure il tuo nome',
  legalOperatorAddressLabel: 'Indirizzo',
  legalOperatorAddressPlaceholder: 'Via Roma 1\n00100 Roma RM\nItalia',
  legalOperatorEmailLabel: 'E-mail di contatto',
  legalOperatorEmailHint:
    'Un indirizzo raggiungibile per richieste legali — mostrato nella tua pagina delle note legali.',
  legalOperatorEmailPlaceholder: 'contact@your-domain.com',
  legalOperatorNoticeLabel: 'Note legali aggiuntive (facoltative)',
  legalOperatorNoticeHint:
    'Tutto ciò che la tua giurisdizione richiede oltre a nome/indirizzo/e-mail — es. partita IVA, iscrizione al registro delle imprese, autorità di vigilanza.',
  legalOperatorNoticePlaceholder: 'es. P.IVA IT12345678901, REA RM-123456',
  legalOperatorSubmit: 'Salva dati del gestore',
  legalOperatorSaved: 'Salvato.',
  complianceFrozenBannerHeading: 'Bacheche nascoste — mancano le informazioni legali',
  complianceFrozenBannerBody:
    'Queste bacheche sono attualmente nascoste ai visitatori perché le informazioni sul gestore legale mancano o non sono confermate. Imposta qui sotto il nome del gestore legale e conferma l’avviso di responsabilità del gestore su ogni bacheca per sbloccarle:',
  deletionScheduledHeading: 'L’account sta per essere eliminato',
  deletionScheduledPart1:
    'Questo account, incluse tutte le bacheche, le idee e i commenti, verrà eliminato definitivamente il',
  deletionScheduledPart2Days: '(',
  deletionScheduledPart3Days: 'giorni rimanenti).',
  deletionScheduledPart2Hours: '(',
  deletionScheduledPart3Hours: 'ore rimanenti).',
  deletionScheduledHint:
    'Finché il periodo di tolleranza è in corso, puoi annullare l’eliminazione qui.',
  deletionScheduledUndo: 'Annulla eliminazione',
  deletionScheduledUndoing: 'Annullamento…',
  deletionScheduledUndoFailed: 'Impossibile annullare l’eliminazione.',
  telemetryHeading: 'Aiuta a migliorare Votepit',
  telemetryToggleLabel: 'Invia dati di utilizzo a Votepit',
  telemetryStateOn:
    'Attiva — solo contatori di installazione/utilizzo delle funzioni, nessun contenuto di bacheche o utenti.',
  telemetryStateOff: 'Disattivata — nulla viene inviato a Votepit.',
  telemetryHint:
    'Votepit sa solo quali pagine vengono aperte e quali azioni vengono eseguite, ad es. «è stata creata una bacheca». Nessun contenuto delle tue bacheche, nessuna idea e nessun indirizzo e-mail. Non vengono usati cookie e l’indirizzo IP viene accorciato prima di essere salvato. Attiva per impostazione predefinita; puoi disattivarla in qualsiasi momento.',
  telemetrySaved: 'Salvato.',
  telemetrySaveFailed: 'Impossibile salvare questa impostazione.',
  exportHeading: 'Esporta i miei dati',
  exportBody:
    'Scarica una copia completa di tutti i dati di questo account (bacheche, idee, voti, commenti, membri, inviti e altro) — come documento JSON o come archivio ZIP con un file CSV per tabella.',
  exportFailed: 'Impossibile scaricare l’esportazione.',
  exportingJson: 'Esportazione…',
  exportJson: 'Esporta come JSON',
  exportingCsv: 'Esportazione…',
  exportCsv: 'Esporta come CSV (ZIP)',
  dangerZoneHeading: 'Zona pericolosa',
  dangerZoneBody:
    'Elimina definitivamente questo account — tutte le bacheche, le idee, i voti, i commenti e le iscrizioni. Il login globale (account e-mail) resta intatto, scompare solo questo account. Dopo la richiesta, hai 48 ore per annullare l’eliminazione qui.',
  deleteAccountButton: 'Elimina account',
  deleteAccountConfirmLabel: 'Digita "{slug}" per confermare',
  deleteAccountConfirmPlaceholder: 'Indirizzo del tuo account',
  deleteAccountConfirmSubmit: 'Elimina definitivamente',
  deleteAccountConfirmCancel: 'Annulla',
  deleteAccountFailed: 'Impossibile richiedere l’eliminazione.',
  deletingAccount: 'Richiesta in corso…',
} satisfies typeof de

export default it
