// machine-drafted, needs native review
import type de from './editPage.de'

const nl = {
  forbiddenError: 'Alleen de persoon die een idee heeft ingediend, kan het bewerken.',
  notFoundError: 'Dit idee bestaat niet meer — het is mogelijk ingetrokken.',
  editWindowExpiredTitle: 'Bewerktermijn verlopen',
  editWindowExpiredError:
    'Ideeën kunnen alleen binnen twee uur na het indienen worden bewerkt. Die termijn is verstreken.',
  loadError: 'Het formulier kon nu niet worden geladen. Probeer het opnieuw.',
  genericError: 'Er is iets misgegaan. Probeer het opnieuw.',
  loading: 'Laden…',
  backToIdea: 'Terug naar idee',
  backToBoard: 'Terug naar board',
  heading: 'Idee bewerken',
  subheading: 'Een duidelijke titel en een korte alinea — platte tekst, geen opmaak.',
  websiteHoneypotLabel: 'Website',
  titleLabel: 'Titel',
  titlePlaceholder: 'Korte, bondige titel',
  titleHint: '3–200 tekens',
  bodyLabel: 'Beschrijving',
  bodyPlaceholder: 'Waar gaat het om? Wat moet er veranderen?',
  bodyHint: 'Minimaal 10 tekens',
  saving: 'Opslaan…',
  saveChanges: 'Wijzigingen opslaan',
} satisfies typeof de

export default nl
