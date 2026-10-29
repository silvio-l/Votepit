/**
 * BoardQrPanel (broaden-use-cases issue 09) — the collapsed "QR code"
 * disclosure next to a board's public link (board settings, setup wizard).
 *
 * a11y sanity without jest-axe (the axe baseline lands with issue 10):
 * keyboard-operable toggle with aria-expanded, a text alternative on the
 * code, the URL as visible text, named download links.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { CopyField } from '@votepit/ui/ds'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { BoardQrPanel } from '../components/BoardQrPanel'
import { qrSvgPath } from '../lib/boardQr'

const URL_ = 'https://votes.example.com/demo-account/sommerfest'

function renderPanel(posterHref?: string) {
  return render(
    <MemoryRouter>
      <BoardQrPanel
        url={URL_}
        fileName="sommerfest"
        posterHref={posterHref}
        renderField={(qrToggle) => (
          <CopyField
            label="Board-Link"
            value={URL_}
            copyLabel="Kopieren"
            copiedLabel="Kopiert"
            actions={qrToggle}
            testId="board-qr-test-copy"
          />
        )}
      />
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('BoardQrPanel', () => {
  it('places the toggle inside the link field, next to the copy button', () => {
    renderPanel()
    const field = screen.getByRole('group', { name: 'Board-Link' })
    expect(field).toContainElement(screen.getByRole('button', { name: 'QR-Code anzeigen' }))
    expect(field).toContainElement(screen.getByRole('button', { name: 'Kopieren' }))
  })

  it('stays collapsed until opened — no code is rendered by default', () => {
    renderPanel()
    const toggle = screen.getByRole('button', { name: 'QR-Code anzeigen' })
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    expect(screen.queryByRole('link')).not.toBeInTheDocument()
  })

  it('opens by keyboard and shows the code with a text alternative and the URL', async () => {
    const user = userEvent.setup()
    renderPanel()

    await user.tab()
    const toggle = screen.getByRole('button', { name: 'QR-Code anzeigen' })
    expect(toggle).toHaveFocus()
    await user.keyboard('{Enter}')

    expect(toggle).toHaveAttribute('aria-expanded', 'true')
    expect(toggle).toHaveAccessibleName('QR-Code ausblenden')
    const code = screen.getByRole('img', { name: `QR-Code für ${URL_}` })
    // The drawn code is exactly the generated one for the public URL.
    expect(code.querySelector('path')?.getAttribute('d')).toBe(qrSvgPath(URL_))
    expect(screen.getByText(URL_)).toBeInTheDocument()
  })

  it('offers SVG and PNG downloads and the poster link, all keyboard-reachable', async () => {
    const user = userEvent.setup()
    renderPanel('/admin/boards/sommerfest/poster')
    await user.click(screen.getByRole('button', { name: 'QR-Code anzeigen' }))

    const svg = screen.getByRole('link', { name: 'SVG herunterladen' })
    expect(svg).toHaveAttribute('download', 'sommerfest-qr.svg')
    expect(svg.getAttribute('href')).toMatch(/^data:image\/svg\+xml;charset=utf-8,/)

    const png = await screen.findByRole('link', { name: 'PNG herunterladen' })
    expect(png).toHaveAttribute('download', 'sommerfest-qr.png')
    expect(png.getAttribute('href')).toMatch(/^data:image\/png;base64,/)

    const poster = screen.getByRole('link', { name: 'Druckbares Poster öffnen' })
    expect(poster).toHaveAttribute('href', '/admin/boards/sommerfest/poster')

    // Tab order: toggle → copy (same field) → SVG → PNG → poster.
    screen.getByRole('button', { name: 'QR-Code ausblenden' }).focus()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Kopieren' })).toHaveFocus()
    await user.tab()
    expect(svg).toHaveFocus()
    await user.tab()
    expect(png).toHaveFocus()
    await user.tab()
    expect(poster).toHaveFocus()
  })

  it('makes no network request while generating', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch')
    const xhrOpen = vi.spyOn(XMLHttpRequest.prototype, 'open')
    const imgSrc = vi.spyOn(HTMLImageElement.prototype, 'src', 'set')
    const user = userEvent.setup()
    renderPanel()

    await user.click(screen.getByRole('button', { name: 'QR-Code anzeigen' }))
    await screen.findByRole('link', { name: 'PNG herunterladen' })

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(xhrOpen).not.toHaveBeenCalled()
    expect(imgSrc).not.toHaveBeenCalled()
    // Every href the panel emits stays local (data: URLs or same-origin paths).
    for (const link of screen.getAllByRole('link')) {
      expect(link.getAttribute('href')).toMatch(/^(data:|\/)/)
    }
  })

  it('collapses again and removes the code', async () => {
    const user = userEvent.setup()
    renderPanel()
    await user.click(screen.getByRole('button', { name: 'QR-Code anzeigen' }))
    await user.click(screen.getByRole('button', { name: 'QR-Code ausblenden' }))
    await waitFor(() => expect(screen.queryByRole('img')).not.toBeInTheDocument())
  })
})
