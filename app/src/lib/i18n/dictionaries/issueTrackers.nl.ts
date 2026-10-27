// machine-drafted, needs native review
import type de from './issueTrackers.de'

const nl = {
  heading: 'Linear & GitHub',
  intro:
    'Koppel Linear of GitHub om met één klik een issue van een idee te maken. Wordt het issue daar afgerond, dan gaat het idee automatisch naar „Klaar”.',
  planUpgradeHint: 'Linear- en GitHub-issues zitten niet in je huidige abonnement.',
  planUpgradeLinkLabel: 'Abonnementen bekijken',
  loadError: 'De koppelingen konden niet worden geladen.',
  providerLinear: 'Linear',
  providerGithub: 'GitHub',
  linearTokenLabel: 'Linear-API-sleutel',
  linearTokenHint: 'Persoonlijke API-sleutel uit Linear → Settings → Security & access.',
  githubTokenLabel: 'GitHub-token',
  githubTokenHint:
    'Fine-grained personal access token met de rechten „Issues: Read and write” voor deze repository.',
  tokenKeepHint: 'Laat leeg om het opgeslagen token ({hint}) te houden.',
  teamKeyLabel: 'Teamsleutel',
  teamKeyHint: 'Bijvoorbeeld ENG.',
  repositoryLabel: 'Repository',
  repositoryHint: 'In de vorm owner/repo.',
  webhookSecretLabel: 'Webhook-geheimsleutel',
  webhookSecretHint:
    'Maak in Linear (Settings → API → Webhooks) een webhook voor „Issues” met de webhook-URL en plak hier het signing-secret. Laat leeg om het opgeslagen secret te houden.',
  connect: 'Koppelen',
  save: 'Opslaan',
  disconnect: 'Ontkoppelen',
  connectedTo: 'Gekoppeld aan {target}, token {hint}',
  syncOn: 'Statussynchronisatie aan',
  syncOff: 'Statussynchronisatie uit',
  webhookUrlLabel: 'Webhook-URL',
  copy: 'Kopiëren',
  copied: 'Gekopieerd',
  githubWebhookSetup:
    'Voeg in GitHub (repository → Settings → Webhooks) een webhook toe met deze URL: content type application/json, het secret hieronder en alleen de gebeurtenis „Issues”.',
  githubSecretOnceLabel: 'Webhook-secret (alleen nu zichtbaar)',
  errorInvalidCredentials: 'Het token is geweigerd.',
  errorTargetNotFound: 'Team of repository niet gevonden, of het token heeft er geen toegang toe.',
  errorUnavailable: 'De dienst is nu niet bereikbaar. Probeer het later opnieuw.',
  errorValidation: 'Controleer je invoer.',
  errorGeneric: 'Dat is niet gelukt. Probeer het opnieuw.',
  panelLabel: 'Issuetracker',
  createIssue: 'Issue aanmaken in {provider}',
  unlink: 'Ontkoppelen',
  unlinkAriaLabel: 'Koppeling met {key} verwijderen',
  errorLinkExists: 'Er bestaat al een issue voor dit idee.',
  errorNotConnected: 'De issuetracker is niet meer gekoppeld.',
  errorRejected: 'De issuetracker heeft het issue geweigerd.',
} satisfies typeof de

export default nl
