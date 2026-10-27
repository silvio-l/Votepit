// machine-drafted, needs native review
import type de from './oauthConsentPage.de'

const it = {
  loading: 'Verifica della richiesta…',
  redirecting: "Ritorno all'applicazione…",
  errorTitle: 'Richiesta non valida',
  invalidRequest:
    "Questa richiesta di autorizzazione non è valida o è scaduta. Riavvia la connessione nell'applicazione.",
  title: '{client} vuole accedere a Votepit',
  intro:
    "L'applicazione ottiene l'accesso alle bacheche che selezioni qui, ciascuna con l'autorizzazione scelta. Agisce per tuo conto tramite l'API Agent.",
  redirectNotice: 'Dopo la tua decisione tornerai a:',
  accountLabel: 'Account',
  accountUnavailable: '{account} (API Agent non inclusa nel piano attuale)',
  boardsLegend: 'Bacheche e autorizzazione',
  levelLabel: 'Autorizzazione per {board}',
  'level.read': 'Lettura',
  'level.write': 'Lettura e scrittura',
  'level.moderate': 'Moderazione',
  noBoards: 'Questo account non ha ancora bacheche.',
  noAccounts:
    "Non puoi concedere l'accesso a nessun account: devi essere proprietario o amministratore di un account.",
  planLimit: "L'API Agent non è inclusa nel piano attuale di questo account.",
  submitFailed: 'Non è stato possibile salvare la tua decisione. Riprova.',
  approve: "Consenti l'accesso",
  deny: 'Rifiuta',
  revokeHint: "Puoi revocare l'accesso in qualsiasi momento in Admin → Token API.",
} satisfies typeof de

export default it
