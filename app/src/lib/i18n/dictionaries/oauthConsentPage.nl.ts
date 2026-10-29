// machine-drafted, needs native review
import type de from './oauthConsentPage.de'

const nl = {
  loading: 'Verzoek wordt gecontroleerd…',
  redirecting: 'Terug naar de applicatie…',
  errorTitle: 'Ongeldig verzoek',
  invalidRequest:
    'Dit autorisatieverzoek is ongeldig of verlopen. Start de verbinding opnieuw in je applicatie.',
  title: '{client} wil toegang tot Votepit',
  intro:
    'De applicatie krijgt toegang tot de borden die je hier kiest, elk met de gekozen rechten. Ze handelt namens jou via de Agent-API.',
  redirectNotice: 'Na je beslissing ga je terug naar:',
  accountLabel: 'Account',
  accountUnavailable: '{account} (Agent-API niet inbegrepen in het huidige abonnement)',
  boardsLegend: 'Borden en rechten',
  levelLabel: 'Rechten voor {board}',
  'level.read': 'Lezen',
  'level.write': 'Lezen en schrijven',
  'level.moderate': 'Modereren',
  noBoards: 'Dit account heeft nog geen borden.',
  noAccounts:
    'Je kunt voor geen enkel account toegang verlenen — daarvoor moet je eigenaar of beheerder van een account zijn.',
  planLimit: 'De Agent-API is niet inbegrepen in het huidige abonnement van dit account.',
  submitFailed: 'Je beslissing kon niet worden opgeslagen. Probeer het opnieuw.',
  approve: 'Toegang toestaan',
  deny: 'Weigeren',
  revokeHint: 'Je kunt de toegang altijd intrekken onder Beheer → API-tokens.',
} satisfies typeof de

export default nl
