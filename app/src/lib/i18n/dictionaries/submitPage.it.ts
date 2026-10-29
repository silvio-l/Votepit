// machine-drafted, needs native review
import type de from './submitPage.de'

const it = {
  loading: 'Caricamento…',
  loadError: 'Al momento non è stato possibile caricare il modulo. Riprova.',
  memberSuspendedError: 'Al momento sei sospeso e non puoi pubblicare contenuti.',
  backToList: 'Torna alla bacheca',
  heading: 'Proponi una nuova idea',
  subtitle: 'Un titolo chiaro e un breve paragrafo — testo semplice, senza formattazione.',
  honeypotLabel: 'Sito web',
  titleLabel: 'Titolo',
  titlePlaceholder: 'Titolo breve e conciso',
  titleHint: '{count}/200 caratteri',
  duplicateHintQuestion: 'Questa idea esiste già?',
  duplicateHintLead: 'Sostenere un’idea esistente ha più peso che aggiungerne una seconda.',
  votesCount: '{count} voti',
  votesCountSingular: '{count} voto',
  submitAnywayButton: 'Proponi comunque come nuova idea',
  bodyLabel: 'Descrizione',
  bodyPlaceholder: 'Di cosa si tratta? Cosa dovrebbe cambiare?',
  bodyHint: '{count}/500 caratteri (min. 10)',
  submitting: 'Invio…',
  submit: 'Proponi idea',
  tagsSuggested: 'Suggerito',
  tagsLabel: 'Tag (facoltativo)',
  tagsHint: 'Scegli fino a {count} tag esistenti. I nuovi tag li crea la moderazione.',
  tagsLimitReached:
    'Hai raggiunto il numero massimo di tag. Rimuovine uno per sceglierne un altro.',
  duplicateAiMatch: 'Corrispondenza IA',
  guestNotice:
    'Stai inviando senza account. La tua idea appare come «Ospite». I link non sono consentiti e non riceverai notifiche. Ritroverai le tue idee solo su questo dispositivo: per questo, dopo l’invio impostiamo un cookie.',
  guestThanksPendingTitle: 'Grazie per la tua idea!',
  guestThanksPendingBody:
    'La tua idea è in attesa di approvazione da parte della moderazione. Finché è in attesa, puoi ritirarla qui sotto su questo dispositivo. Se viene rifiutata, vedrai lì anche il motivo.',
  guestThanksPublishedTitle: 'Grazie per la tua idea!',
  guestThanksPublishedBody:
    'La tua idea è ora visibile. Senza account non puoi più modificarla né ritirarla.',
  guestSubmitAnother: 'Invia un’altra idea',
  guestMineHeading: 'Le mie idee su questo dispositivo',
  guestMineLead: 'Idee che hai inviato senza account in questo browser.',
  guestStatePending: 'In attesa di approvazione',
  guestStatePublished: 'Pubblicata',
  guestStateRejected: 'Rifiutata',
  guestRejectedReason: 'Motivo: {reason}',
  guestWithdraw: 'Ritira',
  guestWithdrawFailed: 'Il ritiro non è riuscito. Riprova.',
  guestErrorUrl: 'Senza account i link non sono consentiti. Rimuovili, per favore.',
  guestErrorCaptcha: 'Il controllo anti-bot non è riuscito. Riprova.',
  guestErrorUnavailable: 'Al momento non è possibile inviare senza account.',
  guestErrorQueueFull: 'Al momento troppe idee sono in attesa di approvazione. Riprova più tardi.',
  guestErrorRateLimited: 'Troppi invii. Attendi un momento e riprova.',
  guestErrorBlocked: 'Il tuo testo contiene termini non consentiti. Riformulalo, per favore.',
  guestErrorRejected: 'L’invio è stato rifiutato. Ricarica la pagina e riprova.',
  tagSuggestionsPlanHint: 'I suggerimenti di tag con IA fanno parte di Pro.',
} satisfies typeof de

export default it
