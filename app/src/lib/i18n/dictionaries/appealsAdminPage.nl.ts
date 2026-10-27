// machine-drafted, needs native review
import type de from './appealsAdminPage.de'

const nl = {
  loading: 'Laden…',
  loadError: 'De pagina kon niet worden geladen.',
  accessDeniedTitle: 'Geen toegang',
  accessDeniedBody:
    'Deze pagina is alleen toegankelijk voor eigenaren, beheerders en moderators van dit account.',
  resolveFailed: 'Het bezwaar kon niet worden afgehandeld. Probeer het opnieuw.',
  title: 'Bezwaren',
  subtitle: 'Bezwaren tegen moderatiebeslissingen beoordelen en afhandelen.',
  appealsHeading: 'Bezwaren ({count} openstaand)',
  emptyState: 'Er zijn geen bezwaren.',
  columnContent: 'Verwijderde inhoud',
  columnReason: 'Reden',
  columnMessage: 'Toelichting bezwaar',
  columnStatus: 'Status',
  columnSubmitted: 'Ingediend',
  columnActions: 'Acties',
  statusPending: 'Openstaand',
  statusUpheld: 'Gehandhaafd',
  statusOverturned: 'Teruggedraaid',
  overturn: 'Terugdraaien',
  uphold: 'Handhaven',
} satisfies typeof de

export default nl
