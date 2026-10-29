/**
 * BoardPosterPage (broaden-use-cases issue 09) — /admin/boards/:slug/poster.
 *
 * Print-friendly A4 poster: board name, neutral call to action, QR code,
 * URL, optional logo. Private boards get no QR code. The toolbar is screen
 * only (print:hidden); the page carries the `vp-poster` hook the print
 * stylesheet in index.css uses (named A4 portrait page, app chrome hidden).
 * a11y sanity without jest-axe (axe baseline lands with issue 10).
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { qrSvgPath } from '../lib/boardQr'
import BoardPosterPage from '../pages/BoardPosterPage'

const BRANDING = {
  board_slug: 'sommerfest',
  board_name: 'Sommerfest 2026',
  primary_color: null,
  secondary_color: null,
  logo_url: null,
  intro: null,
  hide_badge: false,
  translation_enabled: false,
  anonymous_voting_enabled: false,
  visibility: 'public',
  allowed_visibilities: ['public'],
  allowed_branding_fields: [],
  frozen_at: null,
}

const EXPECTED_URL = `${window.location.origin}/sommerfest`

function mockBranding(body: object, status = 200) {
  return vi.spyOn(globalThis, 'fetch').mockImplementation(
    async () =>
      new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
  )
}

function renderPoster() {
  return render(
    <MemoryRouter initialEntries={['/admin/boards/sommerfest/poster']}>
      <Routes>
        <Route path="/admin/boards/:boardSlug/poster" element={<BoardPosterPage />} />
        <Route path="/admin/boards/:boardSlug" element={<div data-testid="admin-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('BoardPosterPage', () => {
  it('renders name, call to action, QR code for the public URL and the URL text', async () => {
    const fetchSpy = mockBranding(BRANDING)
    renderPoster()

    expect(await screen.findByRole('heading', { level: 1, name: 'Sommerfest 2026' })).toBeVisible()
    expect(screen.getByText('Bring deine Ideen ein und stimme ab')).toBeInTheDocument()
    const code = screen.getByRole('img', { name: `QR-Code für ${EXPECTED_URL}` })
    expect(code.querySelector('path')?.getAttribute('d')).toBe(qrSvgPath(EXPECTED_URL))
    expect(screen.getByText(EXPECTED_URL)).toBeInTheDocument()

    // Only the branding read, against the own origin — the QR itself costs no request.
    expect(fetchSpy).toHaveBeenCalledTimes(1)
    expect(String(fetchSpy.mock.calls[0][0])).toBe('/admin/boards/sommerfest/branding')
  })

  it('hides the screen-only toolbar from print and marks the poster for the A4 print sheet', async () => {
    mockBranding(BRANDING)
    const { container } = renderPoster()
    await screen.findByRole('heading', { level: 1 })

    const print = screen.getByRole('button', { name: 'Drucken' })
    const toolbar = print.closest('[data-poster-toolbar]')
    expect(toolbar).not.toBeNull()
    expect(toolbar?.className).toContain('print:hidden')
    expect(container.querySelector('.vp-poster')).not.toBeNull()
  })

  it('prints via the browser dialog and is fully keyboard operable', async () => {
    mockBranding(BRANDING)
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {})
    const user = userEvent.setup()
    renderPoster()
    await screen.findByRole('heading', { level: 1 })

    await user.tab()
    expect(screen.getByRole('link', { name: 'Zurück zu den Board-Einstellungen' })).toHaveFocus()
    await user.tab()
    expect(screen.getByRole('button', { name: 'Drucken' })).toHaveFocus()
    await user.keyboard('{Enter}')
    expect(printSpy).toHaveBeenCalledTimes(1)
  })

  it('shows the board logo when one is set, as decoration next to the name', async () => {
    mockBranding({ ...BRANDING, logo_url: 'https://cdn.example.org/logo.png' })
    const { container } = renderPoster()
    await screen.findByRole('heading', { level: 1 })
    const logo = container.querySelector('img')
    expect(logo).toHaveAttribute('src', 'https://cdn.example.org/logo.png')
    expect(logo).toHaveAttribute('alt', '')
  })

  it('offers a QR code for unlisted boards', async () => {
    mockBranding({ ...BRANDING, visibility: 'unlisted' })
    renderPoster()
    expect(await screen.findByRole('img', { name: /QR-Code für/ })).toBeInTheDocument()
  })

  it('offers no QR code for private boards', async () => {
    mockBranding({ ...BRANDING, visibility: 'private' })
    renderPoster()
    expect(await screen.findByText(/Private Boards haben keinen QR-Code/)).toBeInTheDocument()
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Drucken' })).not.toBeInTheDocument()
  })

  it('redirects anonymous visitors to the login', async () => {
    mockBranding({ error: { key: 'unauthenticated', message: 'Bitte anmelden.' } }, 401)
    renderPoster()
    expect(await screen.findByTestId('login-page')).toBeInTheDocument()
  })

  it('shows an error for non-admins without any QR code', async () => {
    mockBranding({ error: { key: 'account_forbidden', message: 'Kein Zugriff.' } }, 403)
    renderPoster()
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })
})

describe('BoardPosterPage board accent (owner 26a)', () => {
  it('takes the board accent for the title and a rule when the contrast allows it on white', async () => {
    mockBranding({ ...BRANDING, primary_color: '#15604F' })
    renderPoster()
    const title = await screen.findByTestId('board-poster-title')
    expect(title.style.color).toBe('rgb(21, 96, 79)')
    expect(screen.getByTestId('board-poster-accent-rule').style.backgroundColor).toBe(
      'rgb(21, 96, 79)',
    )
  })

  it('darkens the accent title to 4.5:1 on white and keeps the rule at its accent (3:1)', async () => {
    mockBranding({ ...BRANDING, primary_color: '#3A9A5B' })
    renderPoster()
    const title = await screen.findByTestId('board-poster-title')
    expect(title.style.color).toBe('rgb(50, 134, 79)')
    expect(screen.getByTestId('board-poster-accent-rule').style.backgroundColor).toBe(
      'rgb(58, 154, 91)',
    )
  })

  it('corrects a very light accent by lightness, not ink, for the title and the rule', async () => {
    mockBranding({ ...BRANDING, primary_color: '#F5D000' })
    renderPoster()
    const title = await screen.findByTestId('board-poster-title')
    expect(title.style.color).toBe('rgb(137, 116, 0)')
    expect(screen.getByTestId('board-poster-accent-rule').style.backgroundColor).toBe(
      'rgb(171, 146, 0)',
    )
  })

  it('keeps the plain poster without a board accent', async () => {
    mockBranding(BRANDING)
    renderPoster()
    const title = await screen.findByTestId('board-poster-title')
    expect(title.style.color).toBe('')
    expect(screen.queryByTestId('board-poster-accent-rule')).not.toBeInTheDocument()
  })

  it('keeps the QR code black on white whatever the accent is', async () => {
    mockBranding({ ...BRANDING, primary_color: '#15604F' })
    renderPoster()
    await screen.findByTestId('board-poster-title')
    const code = screen.getByRole('img', { name: `QR-Code für ${EXPECTED_URL}` })
    expect(code.querySelector('rect')?.getAttribute('fill')).toBe('#ffffff')
    expect(code.querySelector('path')?.getAttribute('fill')).toBe('#000000')
  })
})

describe('print stylesheet', () => {
  it('puts the poster on a named A4 portrait page and hides the app chrome around it', () => {
    const css = readFileSync(resolve(process.cwd(), 'src/index.css'), 'utf8')
    expect(css).toMatch(/@page vp-poster\s*\{[^}]*size:\s*A4 portrait/)
    expect(css).toMatch(/\.vp-poster\s*\{[^}]*page:\s*vp-poster/)
    expect(css).toMatch(/@media print/)
  })
})
