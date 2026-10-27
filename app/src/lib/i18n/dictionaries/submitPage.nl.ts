// machine-drafted, needs native review
import type de from './submitPage.de'

const nl = {
  loading: 'Laden…',
  loadError: 'Het formulier kon nu niet worden geladen. Probeer het opnieuw.',
  memberSuspendedError: 'Je bent momenteel geschorst voor het indienen van ideeën.',
  backToList: 'Terug naar board',
  heading: 'Nieuw idee indienen',
  subtitle: 'Een duidelijke titel en een korte alinea — platte tekst, geen opmaak.',
  honeypotLabel: 'Website',
  titleLabel: 'Titel',
  titlePlaceholder: 'Korte, bondige titel',
  titleHint: '{count}/200 tekens',
  duplicateHintQuestion: 'Bestaat dit idee al?',
  duplicateHintLead: 'Een bestaand idee steunen heeft meer gewicht dan een tweede toevoegen.',
  votesCount: '{count} stemmen',
  votesCountSingular: '{count} stem',
  submitAnywayButton: 'Toch als nieuw idee indienen',
  bodyLabel: 'Beschrijving',
  bodyPlaceholder: 'Waar gaat het om? Wat moet er veranderen?',
  bodyHint: '{count}/500 tekens (min. 10)',
  submitting: 'Indienen…',
  submit: 'Idee indienen',
  tagsSuggested: 'Voorgesteld',
  tagsLabel: 'Tags (optioneel)',
  tagsHint: 'Kies tot {count} bestaande tags. Nieuwe tags maakt de moderatie aan.',
  tagsLimitReached:
    'Je hebt het maximale aantal tags bereikt. Verwijder er een om een andere te kiezen.',
  duplicateAiMatch: 'AI-match',
  guestNotice:
    'Je dient in zonder account. Je idee verschijnt als ‘Gast’. Links zijn niet toegestaan en je ontvangt geen meldingen. Je ideeën vind je alleen op dit apparaat terug – daarvoor plaatsen we na het indienen een cookie.',
  guestThanksPendingTitle: 'Bedankt voor je idee!',
  guestThanksPendingBody:
    'Je idee wacht op goedkeuring door de moderatie. Zolang het wacht, kun je het hieronder op dit apparaat intrekken. Wordt het afgewezen, dan zie je daar ook de reden.',
  guestThanksPublishedTitle: 'Bedankt voor je idee!',
  guestThanksPublishedBody:
    'Je idee is nu zichtbaar. Zonder account kun je het niet meer bewerken of intrekken.',
  guestSubmitAnother: 'Nog een idee indienen',
  guestMineHeading: 'Mijn ideeën op dit apparaat',
  guestMineLead: 'Ideeën die je zonder account in deze browser hebt ingediend.',
  guestStatePending: 'Wacht op goedkeuring',
  guestStatePublished: 'Gepubliceerd',
  guestStateRejected: 'Afgewezen',
  guestRejectedReason: 'Reden: {reason}',
  guestWithdraw: 'Intrekken',
  guestWithdrawFailed: 'Intrekken is niet gelukt. Probeer het opnieuw.',
  guestErrorUrl: 'Zonder account zijn links niet toegestaan. Verwijder ze alsjeblieft.',
  guestErrorCaptcha: 'De botcontrole is mislukt. Probeer het opnieuw.',
  guestErrorUnavailable: 'Indienen zonder account is op dit moment niet mogelijk.',
  guestErrorQueueFull:
    'Er wachten op dit moment te veel ideeën op goedkeuring. Probeer het later opnieuw.',
  guestErrorRateLimited: 'Te veel inzendingen. Wacht even en probeer het dan opnieuw.',
  guestErrorBlocked: 'Je tekst bevat niet-toegestane termen. Formuleer hem anders.',
  guestErrorRejected: 'De inzending is afgewezen. Laad de pagina opnieuw en probeer het nog eens.',
  tagSuggestionsPlanHint: 'AI-suggesties voor tags zitten in Pro.',
} satisfies typeof de

export default nl
