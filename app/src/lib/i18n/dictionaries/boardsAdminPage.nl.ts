// machine-drafted, needs native review
import type de from './boardsAdminPage.de'

const nl = {
  loading: 'Laden…',
  accessDeniedTitle: 'Toegang geweigerd',
  accessDeniedBody: 'Deze pagina is alleen toegankelijk voor accountbeheerders.',
  loadError: 'De pagina kon niet worden geladen.',
  title: 'Boards',
  subtitle: 'Alle boards in je account.',
  noBoards: 'Nog geen boards aangemaakt.',
  createFirstBoard: 'Maak nu je eerste aan',
  boardsAriaLabel: 'Boards',
  boardsHeading: 'Boards ({count})',
  frozen: 'Bevroren',
  complianceFrozenAccount: 'Verborgen — vul de exploitantgegevens aan',
  complianceFrozenHintAccount:
    'Dit board is verborgen omdat de accountbrede identiteit van de juridische exploitant (naam, adres en contact-e-mailadres) onvolledig is. Klik om die op de Accountpagina aan te vullen — daarmee wordt elk board ontdooid waarvoor de exploitantsbevestiging al is gegeven.',
  complianceFrozenBoard: 'Verborgen — bevestig de exploitantverklaring',
  complianceFrozenHintBoard:
    'De accountbrede naam van de juridische exploitant is ingesteld, maar voor dit board moet de verklaring over de verantwoordelijkheid van de exploitant nog worden bevestigd. Klik om dat hier te doen.',
  manage: 'Instellingen',
  view: 'Bekijken',
  copyLink: 'Boardlink kopiëren',
  copyLinkCopied: 'Link gekopieerd!',
  createHeading: 'Nieuw board aanmaken',
  nameLabel: 'Naam',
  namePlaceholder: 'bijv. Onze ideeën',
  slugLabel: 'Slug',
  slugPlaceholder: 'bijv. onze-ideeen',
  slugHint: 'Voorgesteld op basis van de naam, maar vrij aan te passen.',
  createSubmitting: 'Aanmaken…',
  createSubmit: 'Board aanmaken',
  newBoard: 'Nieuw board',
  statBoards: 'Boards',
  statIdeas: 'Ideeën',
  statVotes: 'Stemmen',
  statCaptionAll: 'over alle boards',
  ideasCount: '{count} ideeën',
  ideasCountSingular: '{count} idee',
  votesCount: '{count} stemmen',
  votesCountSingular: '{count} stem',
  createHint:
    'Een board is een openbare pagina met een eigen link waar mensen ideeën indienen en stemmen.',
  emptyDescription:
    'Maak een board aan en deel de link — ideeën en stemmen verzamelen zich daar vanzelf.',
  operatorAckLabel:
    'Ik bevestig dat ik de juridische exploitant van dit board ben — verantwoordelijk voor de communityregels, de moderatie en eventuele verplichtingen op boardniveau rond juridische informatie en privacy.',
} satisfies typeof de

export default nl
