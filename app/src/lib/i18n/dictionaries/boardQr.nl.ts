// machine-drafted, needs native review
import type de from './boardQr.de'

const nl = {
  show: 'QR-code tonen',
  hide: 'QR-code verbergen',
  alt: 'QR-code voor {url}',
  hint: 'De code bevat alleen de openbare boardlink, geen inloggegevens.',
  downloadSvg: 'SVG downloaden',
  downloadPng: 'PNG downloaden',
  openPoster: 'Afdrukbare poster openen',
  posterRegion: 'Poster',
  posterCallToAction: 'Deel je ideeën en stem mee',
  posterScanHint: 'Scan de code of open de link',
  posterPrint: 'Afdrukken',
  posterBack: 'Terug naar de boardinstellingen',
  posterPrintHint: "Past op één A4-pagina staand. Menu's en knoppen worden niet afgedrukt.",
  posterPrivate:
    'Privéboards hebben geen QR-code: de link werkt alleen voor ingelogde leden. Maak het board openbaar of niet-vermeld om een poster te maken.',
} satisfies typeof de

export default nl
