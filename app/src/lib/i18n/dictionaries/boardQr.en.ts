import type de from './boardQr.de'

const en = {
  show: 'Show QR code',
  hide: 'Hide QR code',
  alt: 'QR code for {url}',
  hint: 'The code contains only the public board link, no sign-in data.',
  downloadSvg: 'Download SVG',
  downloadPng: 'Download PNG',
  openPoster: 'Open printable poster',
  posterRegion: 'Poster',
  posterCallToAction: 'Share your ideas and vote',
  posterScanHint: 'Scan the code or open the link',
  posterPrint: 'Print',
  posterBack: 'Back to board settings',
  posterPrintHint: 'Fits one A4 page in portrait. Menus and buttons are not printed.',
  posterPrivate:
    'Private boards have no QR code: their link only works for signed-in members. Make the board public or unlisted to create a poster.',
} satisfies typeof de

export default en
