// machine-drafted, needs native review
import type de from './boardQr.de'

const it = {
  show: 'Mostra codice QR',
  hide: 'Nascondi codice QR',
  alt: 'Codice QR per {url}',
  hint: 'Il codice contiene solo il link pubblico della bacheca, nessun dato di accesso.',
  downloadSvg: 'Scarica SVG',
  downloadPng: 'Scarica PNG',
  openPoster: 'Apri il poster stampabile',
  posterRegion: 'Poster',
  posterCallToAction: 'Condividi le tue idee e vota',
  posterScanHint: 'Scansiona il codice o apri il link',
  posterPrint: 'Stampa',
  posterBack: 'Torna alle impostazioni della bacheca',
  posterPrintHint: 'Sta in una pagina A4 verticale. Menu e pulsanti non vengono stampati.',
  posterPrivate:
    "Le bacheche private non hanno un codice QR: il loro link funziona solo per i membri che hanno effettuato l'accesso. Rendi la bacheca pubblica o non in elenco per creare un poster.",
} satisfies typeof de

export default it
