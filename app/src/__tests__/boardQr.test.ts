/**
 * Board QR code (broaden-use-cases issue 09) — pure generation layer.
 *
 * The decisive property: the rendered module matrix decodes back to EXACTLY
 * the public board URL. jsQR (Apache-2.0, devDependency only) decodes a
 * rasterised copy of the matrix — the same matrix the inline SVG and the SVG
 * download are drawn from — so the test covers what a phone camera sees.
 */

import jsQR from 'jsqr'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { QR_MARGIN, qrAllowedFor, qrMatrix, qrSvgMarkup, qrSvgPath } from '../lib/boardQr'

/** Rasterises the matrix (incl. quiet zone) to RGBA pixels for the decoder. */
function rasterise(url: string, scale = 4) {
  const { size, isDark } = qrMatrix(url)
  const full = size + QR_MARGIN * 2
  const px = full * scale
  const data = new Uint8ClampedArray(px * px * 4).fill(255)
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      if (!isDark(x, y)) continue
      for (let dy = 0; dy < scale; dy++) {
        for (let dx = 0; dx < scale; dx++) {
          const i = (((y + QR_MARGIN) * scale + dy) * px + (x + QR_MARGIN) * scale + dx) * 4
          data[i] = 0
          data[i + 1] = 0
          data[i + 2] = 0
        }
      }
    }
  }
  return { data, px }
}

/** Rasterises the SVG path string itself (not the matrix) — proves the drawn shapes match. */
function rasterisePath(d: string, full: number, scale = 4) {
  const px = full * scale
  const data = new Uint8ClampedArray(px * px * 4).fill(255)
  for (const m of d.matchAll(/M(\d+) (\d+)h1v1h-1z/g)) {
    const x = Number(m[1])
    const y = Number(m[2])
    for (let dy = 0; dy < scale; dy++) {
      for (let dx = 0; dx < scale; dx++) {
        const i = ((y * scale + dy) * px + x * scale + dx) * 4
        data[i] = 0
        data[i + 1] = 0
        data[i + 2] = 0
      }
    }
  }
  return { data, px }
}

const URLS = [
  'https://votes.example.com/acme/product-feedback',
  'http://localhost:5173/sommerfest-2026',
  'https://votes.example.org/a',
]

afterEach(() => {
  vi.restoreAllMocks()
})

describe('qrMatrix', () => {
  it.each(URLS)('decodes to the exact board URL: %s', (url) => {
    const { data, px } = rasterise(url)
    const decoded = jsQR(data, px, px)
    expect(decoded?.data).toBe(url)
  })

  it.each(URLS)('the SVG path draws a code that decodes to the exact URL: %s', (url) => {
    const { size } = qrMatrix(url)
    const { data, px } = rasterisePath(qrSvgPath(url), size + QR_MARGIN * 2)
    expect(jsQR(data, px, px)?.data).toBe(url)
  })

  it('never touches the network while generating', () => {
    const fetchSpy = vi.fn()
    vi.stubGlobal('fetch', fetchSpy)
    const xhrOpen = vi.spyOn(XMLHttpRequest.prototype, 'open')
    const imgSrc = vi.spyOn(HTMLImageElement.prototype, 'src', 'set')

    qrMatrix(URLS[0])
    qrSvgPath(URLS[0])
    qrSvgMarkup(URLS[0])

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(xhrOpen).not.toHaveBeenCalled()
    expect(imgSrc).not.toHaveBeenCalled()
  })
})

describe('qrSvgMarkup', () => {
  it('is a self-contained SVG file with no external references or scripts', () => {
    const svg = qrSvgMarkup(URLS[0])
    expect(svg.startsWith('<svg xmlns="http://www.w3.org/2000/svg"')).toBe(true)
    expect(svg).not.toMatch(/<script|href=|style=/i)
    expect(svg).toContain(qrSvgPath(URLS[0]))
  })
})

describe('qrAllowedFor', () => {
  it('offers the QR code for public and unlisted boards only', () => {
    expect(qrAllowedFor('public')).toBe(true)
    expect(qrAllowedFor('unlisted')).toBe(true)
    expect(qrAllowedFor('private')).toBe(false)
  })
})
