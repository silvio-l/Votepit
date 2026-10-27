/**
 * Board QR code (broaden-use-cases issue 09): encodes a board's public URL as
 * a QR code entirely client-side, with the qrcode package the app already
 * ships for TOTP setup. No external QR service, no network request.
 *
 * The on-screen code is an inline SVG drawn from `qrSvgPath` (presentation
 * attributes only, so the strict CSP with `style-src-attr 'none'` holds),
 * the SVG download is the same path, and the PNG download comes from the
 * same library with the same error-correction level.
 *
 * Only the public board URL is ever encoded — callers pass `fullBoardUrl()`,
 * never a token or anything session-bound. Private boards get no QR code at
 * all (`qrAllowedFor`): their link only works for signed-in members anyway.
 */

import QRCode from 'qrcode'
import type { BoardVisibility } from './api'

/** Quiet zone in modules (the QR spec asks for 4). */
export const QR_MARGIN = 4

/** 'M' (~15 % recovery) survives a creased or partly covered poster. */
const ERROR_CORRECTION = 'M' as const

export function qrAllowedFor(visibility: BoardVisibility): boolean {
  return visibility !== 'private'
}

export function qrMatrix(url: string): { size: number; isDark: (x: number, y: number) => boolean } {
  const { modules } = QRCode.create(url, { errorCorrectionLevel: ERROR_CORRECTION })
  return {
    size: modules.size,
    isDark: (x, y) => modules.get(y, x) === 1,
  }
}

/** One unit square per dark module, offset by the quiet zone. */
export function qrSvgPath(url: string): string {
  const { size, isDark } = qrMatrix(url)
  let d = ''
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (isDark(x, y)) d += `M${x + QR_MARGIN} ${y + QR_MARGIN}h1v1h-1z`
    }
  }
  return d
}

/** Side length of the SVG viewBox in modules, quiet zone included. */
export function qrViewBoxSize(url: string): number {
  return qrMatrix(url).size + QR_MARGIN * 2
}

/** Standalone SVG file content for the download — paths only, no text, no references. */
export function qrSvgMarkup(url: string): string {
  const n = qrViewBoxSize(url)
  return (
    `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n} ${n}" width="${n * 10}" height="${n * 10}" shape-rendering="crispEdges">` +
    // Fixed black on white (exception): a printed or pasted QR code must scan in both schemes.
    `<rect width="${n}" height="${n}" fill="#ffffff"/>` +
    `<path d="${qrSvgPath(url)}" fill="#000000"/>` +
    '</svg>'
  )
}

export function qrSvgDataUrl(url: string): string {
  return `data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrSvgMarkup(url))}`
}

/** PNG for download (print shops, slide decks) — rendered locally to a data: URL. */
export function qrPngDataUrl(url: string): Promise<string> {
  return QRCode.toDataURL(url, {
    errorCorrectionLevel: ERROR_CORRECTION,
    margin: QR_MARGIN,
    width: 1024,
  })
}
