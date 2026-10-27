// machine-drafted, needs native review
import type de from './inviteAcceptPage.de'

const it = {
  invalidTitle: 'Questo link di invito non funziona più',
  invalidBody:
    'Il link non è valido, è scaduto o è già stato usato. Chiedi a chi ti ha invitato un nuovo link.',
  acceptFailedFallback: 'Non è stato possibile accettare l’invito. Riprova tra poco.',
  accepting: 'Accettazione dell’invito…',
  doneTitle: 'Benvenuto nel team',
  doneBodyOwner: 'Ora sei il proprietario di questo account.',
  doneBodyAdmin: 'Ora sei un amministratore di questo account.',
  doneBodyModerator: 'Ora sei un moderatore di questo account.',
  doneBodyMember: 'Ora hai accesso alle bacheche private di questo account.',
  goToMembers: 'Vai alla panoramica dei membri',
  goToBoard: 'Vai alla bacheca',
  errorTitle: 'Momentaneamente non raggiungibile',
  teamLimitTitle: 'Il team è al completo',
  teamLimitBody:
    'Questo account ha raggiunto il limite di membri del suo piano. Contatta la persona che ti ha invitato – il tuo link resta valido.',
  loginCta: 'Vai al login',
  retryCta: 'Riprova',
  mismatchTitle: 'Hai effettuato l’accesso con l’account sbagliato',
  mismatchBody:
    'Questo invito è per un altro indirizzo e-mail. Accedi con l’account invitato per accettarlo.',
  mismatchCurrentAccount: 'Accesso attuale come: {publicId}',
  mismatchAliasHint:
    'Se hai ricevuto tu stesso questa e-mail: un inoltro o una casella alias conta come un indirizzo diverso da quello a cui è stato inviato l’invito.',
  switchAccountCta: 'Esci e cambia account',
  switchingAccount: 'Uscita in corso…',
} satisfies typeof de

export default it
