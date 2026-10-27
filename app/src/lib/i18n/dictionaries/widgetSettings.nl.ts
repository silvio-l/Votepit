// machine-drafted, needs native review
import type de from './widgetSettings.de'

const nl = {
  heading: 'Insluitbare widget',
  intro:
    'Toon dit bord als feedbackwidget op je eigen website. Ingelogde gebruikers van je app stemmen en reageren direct in de widget.',
  statusLive: 'Actief',
  statusOff: 'Uit',
  loadFailed: 'De widgetinstellingen konden niet worden geladen.',
  planLocked: 'De widget zit niet in je huidige abonnement. Je instellingen blijven bewaard.',
  planUpgradeLinkLabel: 'Abonnementen bekijken',
  privateBoard:
    'Privéborden kunnen niet worden ingesloten. Zet de zichtbaarheid op openbaar of niet vermeld.',
  originsLabel: 'Toegestane websites',
  originsHint:
    'Eén adres per regel, bijv. https://www.voorbeeld.nl (maximaal {max}). Alleen sitesadressen: geen subpagina’s, geen jokertekens. Leeg = widget uit.',
  originsInvalid:
    'Minstens één regel is geen geldige https-origin zonder pad of wildcard, of het zijn er meer dan {max}.',
  originsSave: 'Origins opslaan',
  originsSaved: 'Opgeslagen.',
  saveFailed: 'Opslaan mislukt.',
  secretHeading: 'Geheime sleutel om ingelogde gebruikers van je app te herkennen',
  secretConfigured: 'Er is een secret ingesteld. Het wordt nooit meer getoond.',
  secretMissing: 'Nog geen secret. Zonder secret is de widget voor bezoekers alleen-lezen.',
  secretRevealedTitle: 'Kopieer het nu: het secret wordt maar één keer getoond',
  secretFieldLabel: 'Geheime sleutel (alleen op je server bewaren)',
  secretRotate: 'Secret roteren',
  secretGenerate: 'Secret aanmaken',
  rotateFailed: 'Het secret kon niet worden aangemaakt.',
  rotateConfirmTitle: 'Secret roteren?',
  rotateConfirmBody:
    'Het oude secret werkt meteen niet meer en alle open widgetsessies eindigen. Je website heeft daarna het nieuwe secret nodig.',
  snippetLabel: 'Insluitcode',
  promptLabel: 'Prompt voor je AI-codeeragent',
  lastSeen: 'Laatst gezien op {origin} op {date}.',
  notSeen: 'De widget is nog niet geladen op een toegestane website.',
} satisfies typeof de

export default nl
