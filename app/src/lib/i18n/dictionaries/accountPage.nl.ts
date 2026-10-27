// machine-drafted, needs native review
import type de from './accountPage.de'

const nl = {
  loading: 'Laden…',
  accessDeniedTitle: 'Geen toegang',
  accessDeniedBody: 'Deze pagina is alleen toegankelijk voor de eigenaar van het account.',
  loadError: 'De pagina kon niet worden geladen.',
  title: 'Account',
  subtitle: 'Gegevensexport en verwijdering voor dit account.',
  accountHeading: 'Dit account',
  nameLabel: 'Naam',
  slugLabel: 'Adres (korte naam)',
  slugHint:
    'Deel van het accountadres, bijv. „mijnclub“ in …/mijnclub/… Alleen kleine letters, cijfers en koppeltekens. Uniek binnen heel Votepit.',
  slugChecking: 'Controleren…',
  slugAvailable: 'Dit adres is beschikbaar.',
  slugTaken: 'Dit adres is al in gebruik.',
  savingEllipsis: 'Opslaan…',
  renameSubmit: 'Naam en adres opslaan',
  renameSaved: 'Opgeslagen.',
  saveFailedGeneric: 'Opslaan mislukt. Probeer het opnieuw.',
  legalOperatorHeading: 'Gegevens van de boardexploitant',
  legalOperatorBody:
    'Getoond op je openbare pagina met juridische informatie (gelinkt vanaf elk openbaar board). Naam, adres en contact-e-mailadres zijn allemaal verplicht voordat een board openbaar of niet-vermeld kan worden gemaakt.',
  legalOperatorNameLabel: 'Naam exploitant',
  legalOperatorNameHint: 'Je bedrijfsnaam, of je eigen naam als je het alleen exploiteert.',
  legalOperatorNamePlaceholder: 'bijv. Acme BV, of je eigen naam',
  legalOperatorAddressLabel: 'Adres',
  legalOperatorAddressPlaceholder: 'Hoofdstraat 1\n1234 AB Amsterdam\nNederland',
  legalOperatorEmailLabel: 'Contact-e-mailadres',
  legalOperatorEmailHint:
    'Een bereikbaar adres voor juridische vragen — getoond op je pagina met juridische informatie.',
  legalOperatorEmailPlaceholder: 'contact@jouw-domein.nl',
  legalOperatorNoticeLabel: 'Aanvullende juridische informatie (optioneel)',
  legalOperatorNoticeHint:
    'Alles wat jouw rechtsgebied vereist naast naam/adres/e-mail — bijv. btw-id, KvK-nummer, toezichthouder.',
  legalOperatorNoticePlaceholder: 'bijv. btw-id NL123456789B01, KvK 12345678',
  legalOperatorSubmit: 'Exploitantgegevens opslaan',
  legalOperatorSaved: 'Opgeslagen.',
  complianceFrozenBannerHeading: 'Boards verborgen — juridische informatie ontbreekt',
  complianceFrozenBannerBody:
    'Deze boards zijn momenteel verborgen voor bezoekers omdat informatie over de juridische exploitant ontbreekt of niet is bevestigd. Stel hieronder de naam van je juridische exploitant in en bevestig op elk board de verklaring over de verantwoordelijkheid van de exploitant om ze te ontdooien:',
  deletionScheduledHeading: 'Account wordt verwijderd',
  deletionScheduledPart1: 'Dit account, inclusief alle boards, ideeën en reacties, wordt op',
  deletionScheduledPart2Days: 'definitief verwijderd (',
  deletionScheduledPart3Days: 'dagen resterend).',
  deletionScheduledPart2Hours: 'definitief verwijderd (',
  deletionScheduledPart3Hours: 'uur resterend).',
  deletionScheduledHint: 'Zolang de bedenktijd loopt, kun je de verwijdering hier annuleren.',
  deletionScheduledUndo: 'Verwijdering annuleren',
  deletionScheduledUndoing: 'Annuleren…',
  deletionScheduledUndoFailed: 'De verwijdering kon niet worden geannuleerd.',
  telemetryHeading: 'Help Votepit te verbeteren',
  telemetryToggleLabel: 'Gebruiksgegevens naar Votepit sturen',
  telemetryStateOn:
    'Aan — alleen tellers voor installaties/functiegebruik, geen board- of gebruikersinhoud.',
  telemetryStateOff: 'Uit — er wordt niets naar Votepit verstuurd.',
  telemetryHint:
    'Votepit ziet alleen welke pagina’s worden geopend en welke acties worden uitgevoerd, bijv. “er is een board aangemaakt”. Geen inhoud van je boards, geen ideeën en geen e-mailadressen. Er worden geen cookies geplaatst en het IP-adres wordt ingekort voordat het wordt opgeslagen. Standaard aan; je kunt het op elk moment uitzetten.',
  telemetrySaved: 'Opgeslagen.',
  telemetrySaveFailed: 'Deze instelling kon niet worden opgeslagen.',
  exportHeading: 'Mijn gegevens exporteren',
  exportBody:
    'Download een volledige kopie van alle gegevens van dit account (boards, ideeën, stemmen, reacties, leden, uitnodigingen en meer) — als JSON-document of als ZIP-archief met één CSV-bestand per tabel.',
  exportFailed: 'De export kon niet worden gedownload.',
  exportingJson: 'Exporteren…',
  exportJson: 'Exporteren als JSON',
  exportingCsv: 'Exporteren…',
  exportCsv: 'Exporteren als CSV (ZIP)',
  dangerZoneHeading: 'Gevarenzone',
  dangerZoneBody:
    'Verwijdert dit account definitief — alle boards, ideeën, stemmen, reacties en lidmaatschappen. De globale login (e-mailaccount) blijft bestaan, alleen dit account verdwijnt. Na het aanvragen heb je 48 uur om de verwijdering hier te annuleren.',
  deleteAccountButton: 'Account verwijderen',
  deleteAccountConfirmLabel: 'Typ "{slug}" ter bevestiging',
  deleteAccountConfirmPlaceholder: 'Adres van je account',
  deleteAccountConfirmSubmit: 'Definitief verwijderen',
  deleteAccountConfirmCancel: 'Annuleren',
  deleteAccountFailed: 'De verwijdering kon niet worden aangevraagd.',
  deletingAccount: 'Aanvragen…',
} satisfies typeof de

export default nl
