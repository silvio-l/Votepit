// machine-drafted, needs native review
import type de from './widgetSettings.de'

const it = {
  heading: 'Widget incorporabile',
  intro:
    'Mostra questa bacheca come widget di feedback sul tuo sito. Gli utenti connessi della tua app votano e commentano direttamente nel widget.',
  statusLive: 'Attivo',
  statusOff: 'Disattivato',
  loadFailed: 'Impossibile caricare le impostazioni del widget.',
  planLocked: 'Il widget non è incluso nel tuo piano attuale. Le impostazioni vengono conservate.',
  planUpgradeLinkLabel: 'Vedi i piani',
  privateBoard:
    'Le bacheche private non possono essere incorporate. Imposta la visibilità su pubblica o non in elenco.',
  originsLabel: 'Siti consentiti',
  originsHint:
    'Un indirizzo per riga, ad es. https://www.esempio.com (fino a {max}). Solo indirizzi di sito: niente sottopagine né jolly. Vuoto = widget disattivato.',
  originsInvalid:
    "Almeno una voce non è un'origine https valida senza percorso o carattere jolly, oppure sono più di {max}.",
  originsSave: 'Salva origini',
  originsSaved: 'Salvato.',
  saveFailed: 'Salvataggio non riuscito.',
  secretHeading: 'Chiave segreta per riconoscere gli utenti connessi della tua app',
  secretConfigured: 'Un secret è configurato. Non verrà più mostrato.',
  secretMissing: 'Nessun secret ancora. Senza secret il widget è in sola lettura per i visitatori.',
  secretRevealedTitle: 'Copialo ora: il secret viene mostrato solo questa volta',
  secretFieldLabel: 'Chiave segreta (salvala solo sul tuo server)',
  secretRotate: 'Ruota secret',
  secretGenerate: 'Genera secret',
  rotateFailed: 'Impossibile generare il secret.',
  rotateConfirmTitle: 'Ruotare il secret?',
  rotateConfirmBody:
    'Il vecchio secret smette subito di funzionare e tutte le sessioni aperte del widget terminano. Il tuo sito avrà poi bisogno del nuovo secret.',
  snippetLabel: 'Codice di incorporamento',
  promptLabel: 'Prompt per il tuo agente di programmazione IA',
  lastSeen: "Visto l'ultima volta su {origin} il {date}.",
  notSeen: 'Il widget non è ancora stato caricato su un sito consentito.',
} satisfies typeof de

export default it
