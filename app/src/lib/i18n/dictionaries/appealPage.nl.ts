// machine-drafted, needs native review
import type de from './appealPage.de'

const nl = {
  loading: 'Laden…',
  loadError: 'De pagina kon niet worden geladen.',
  scopeLabel: 'Bezwaar',
  notFoundError: 'Deze verwijdering kon niet worden gevonden.',
  notAuthorError: 'Alleen de oorspronkelijke auteur kan bezwaar maken tegen deze verwijdering.',
  alreadyPendingError: 'Er wordt al een bezwaar tegen deze verwijdering beoordeeld.',
  submitFailed: 'Het bezwaar kon niet worden ingediend. Probeer het opnieuw.',
  backToBoard: 'Terug naar board',
  heading: 'Bezwaar maken tegen deze verwijdering',
  subtitle:
    'Denk je dat je inhoud ten onrechte is verwijderd? Leg hier uit waarom. Een moderator beoordeelt je bezwaar.',
  messageLabel: 'Waarom moet deze verwijdering worden heroverwogen?',
  messagePlaceholder: 'Beschrijf waarom je het niet eens bent met de verwijdering…',
  messageHint: '{count} tekens',
  submit: 'Bezwaar indienen',
  submitting: 'Indienen…',
  submittedTitle: 'Bezwaar ingediend',
  submittedBody:
    'Bedankt, je bezwaar is ingediend. Een moderator beoordeelt het en je krijgt bericht over de uitkomst.',
} satisfies typeof de

export default nl
