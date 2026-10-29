// machine-drafted, needs native review
import type de from './widget.de'

const it = {
  title: 'Feedback',
  close: 'Chiudi',
  loading: 'Caricamento…',
  loadFailed: 'Impossibile caricare la bacheca.',
  empty: 'Ancora nessuna idea.',
  upvote: 'Vota a favore',
  downvote: 'Vota contro',
  comments: '{count} commenti',
  commentSingular: '{count} commento',
  fewVotes: 'Ancora pochi voti – il risultato può ancora cambiare molto',
  approval: '{value}% approvazione',
  back: 'Indietro',
  readOnlyHint: 'Per votare e commentare, la bacheca si apre in una nuova scheda.',
  openBoard: 'Apri la bacheca',
  openIdea: 'Apri nella bacheca',
  commentLabel: 'Commento',
  commentSubmit: 'Commenta',
  newIdea: 'Nuova idea',
  titleLabel: 'Titolo',
  bodyLabel: 'Descrizione',
  submit: 'Invia idea',
  submitted: 'Grazie! La tua idea è stata inviata.',
  submittedPending: 'Grazie! La tua idea comparirà non appena il team l’avrà approvata.',
  actionFailed: 'Non ha funzionato. Riprova.',
  verificationOnBoard:
    'Conferma di non essere un bot. È possibile solo direttamente sulla bacheca.',
  continueOnBoard: 'Continua sulla bacheca',
  signedInAs: 'Connesso come {name}',
} satisfies typeof de

export default it
