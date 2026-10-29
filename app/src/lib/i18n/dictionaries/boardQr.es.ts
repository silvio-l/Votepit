// machine-drafted, needs native review
import type de from './boardQr.de'

const es = {
  show: 'Mostrar código QR',
  hide: 'Ocultar código QR',
  alt: 'Código QR para {url}',
  hint: 'El código solo contiene el enlace público del tablero, sin datos de inicio de sesión.',
  downloadSvg: 'Descargar SVG',
  downloadPng: 'Descargar PNG',
  openPoster: 'Abrir póster imprimible',
  posterRegion: 'Póster',
  posterCallToAction: 'Comparte tus ideas y vota',
  posterScanHint: 'Escanea el código o abre el enlace',
  posterPrint: 'Imprimir',
  posterBack: 'Volver a la configuración del tablero',
  posterPrintHint: 'Cabe en una página A4 en vertical. Los menús y botones no se imprimen.',
  posterPrivate:
    'Los tableros privados no tienen código QR: su enlace solo funciona para miembros con sesión iniciada. Haz el tablero público o no listado para crear un póster.',
} satisfies typeof de

export default es
