// machine-drafted, needs native review
import type de from './issueTrackers.de'

const it = {
  heading: 'Linear e GitHub',
  intro:
    "Collega Linear o GitHub per trasformare un'idea in una issue con un clic. Quando la issue viene completata lì, l'idea passa automaticamente a «Fatto».",
  planUpgradeHint: 'Le issue di Linear e GitHub non sono incluse nel tuo piano attuale.',
  planUpgradeLinkLabel: 'Vedi i piani',
  loadError: 'Impossibile caricare i collegamenti.',
  providerLinear: 'Linear',
  providerGithub: 'GitHub',
  linearTokenLabel: 'Chiave API di Linear',
  linearTokenHint: 'Chiave API personale da Linear → Settings → Security & access.',
  githubTokenLabel: 'Token GitHub',
  githubTokenHint:
    'Token di accesso personale «fine-grained» con il permesso «Issues: Read and write» per questo repository.',
  tokenKeepHint: 'Lascia vuoto per mantenere il token salvato ({hint}).',
  teamKeyLabel: 'Chiave del team',
  teamKeyHint: 'Ad esempio ENG.',
  repositoryLabel: 'Repository',
  repositoryHint: 'Nel formato owner/repo.',
  webhookSecretLabel: 'Chiave segreta del webhook',
  webhookSecretHint:
    "In Linear (Settings → API → Webhooks) crea un webhook per «Issues» con l'URL del webhook e incolla qui il suo segreto di firma. Lascia vuoto per mantenere quello salvato.",
  connect: 'Collega',
  save: 'Salva',
  disconnect: 'Scollega',
  connectedTo: 'Collegato a {target}, token {hint}',
  syncOn: 'Sincronizzazione dello stato attiva',
  syncOff: 'Sincronizzazione dello stato disattivata',
  webhookUrlLabel: 'URL del webhook',
  copy: 'Copia',
  copied: 'Copiato',
  githubWebhookSetup:
    "In GitHub (repository → Settings → Webhooks) aggiungi un webhook con questo URL: content type application/json, il segreto qui sotto e solo l'evento «Issues».",
  githubSecretOnceLabel: 'Segreto del webhook (mostrato solo ora)',
  errorInvalidCredentials: 'Il token è stato rifiutato.',
  errorTargetNotFound: 'Team o repository non trovato, oppure il token non vi ha accesso.',
  errorUnavailable: 'Il servizio non è raggiungibile al momento. Riprova più tardi.',
  errorValidation: 'Controlla i dati inseriti.',
  errorGeneric: 'Non ha funzionato. Riprova.',
  panelLabel: 'Issue tracker',
  createIssue: 'Crea issue in {provider}',
  unlink: 'Scollega',
  unlinkAriaLabel: 'Rimuovi il collegamento a {key}',
  errorLinkExists: "Esiste già una issue per quest'idea.",
  errorNotConnected: "L'issue tracker non è più collegato.",
  errorRejected: "L'issue tracker ha rifiutato la issue.",
} satisfies typeof de

export default it
