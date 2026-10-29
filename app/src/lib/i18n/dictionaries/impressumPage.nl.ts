// machine-drafted, needs native review
import type de from './impressumPage.de'

const nl = {
  loading: 'Laden…',
  errorHeading: 'Deze pagina kon niet worden geladen',
  errorLoadFailed: 'Probeer het zo meteen opnieuw.',
  notFoundTitle: 'Deze juridische informatie is er nu even niet',
  notFoundDescription:
    'Misschien is ze verplaatst of verwijderd. Vanaf de startpagina kun je verder.',
  retry: 'Opnieuw proberen',
  heading: 'Juridische informatie',
  subtitle: 'Juridische informatie voor {name}, zoals verstrekt door de exploitant.',
  nameLabel: 'Exploitant',
  addressLabel: 'Adres',
  emailLabel: 'Contact',
  noticeHeading: 'Aanvullende informatie',
  missingTitle: 'Geen juridische informatie beschikbaar',
  missingBody: 'De exploitant van dit board heeft nog geen juridische informatie verstrekt.',
  privacyHeading: 'Gegevensbescherming (AVG)',
  privacyBody:
    'Dit board draait op Votepit. Votepit verwerkt gegevens in opdracht van de hierboven genoemde exploitant, die volgens de AVG de verwerkingsverantwoordelijke voor dit board is. Voor details over hoe Votepit zelf als platform gegevens verwerkt, zie',
  privacyLinkLabel: 'het privacybeleid van Votepit',
} satisfies typeof de

export default nl
