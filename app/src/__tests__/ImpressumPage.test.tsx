/**
 * RTL tests for ImpressumPage (/:boardSlug/impressum).
 *
 * - A missing or non-public board shows the shared not-found page with the
 *   legal-notice copy, and the page does not echo the requested slug.
 * - A nonexistent and a foreign/private board are answered with the same 404
 *   and must render identically (no existence hint).
 * - Other failures keep the inline error with a retry action.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setEdition } from '../lib/edition'
import ImpressumPage from '../pages/ImpressumPage'

const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

function mockFetch(boardBody: object, boardStatus: number) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    const isBootstrap = url.includes('/api/bootstrap')
    return new Response(JSON.stringify(isBootstrap ? ANON_BOOTSTRAP : boardBody), {
      status: isBootstrap ? 200 : boardStatus,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderPage(boardSlug: string) {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/impressum`]}>
      <Routes>
        <Route path="/:boardSlug/impressum" element={<ImpressumPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  // Pin the language: jsdom reports en-US, the assertions use the German copy.
  localStorage.setItem('vp_lang', 'de')
  vi.stubGlobal(
    'matchMedia',
    vi.fn(() => ({
      matches: false,
      media: '',
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  )
})

afterEach(() => {
  vi.unstubAllGlobals()
  localStorage.clear()
})

describe('ImpressumPage', () => {
  it('renders the shared not-found page with the legal-notice copy on a 404', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Not found.' } }, 404)

    renderPage('missing-board')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(
        'Dieses Impressum ist gerade nicht hier',
      ),
    )
    expect(screen.getByTestId('not-found-message')).toHaveTextContent(
      'Vielleicht wurde die Seite verschoben oder entfernt.',
    )
    expect(screen.getByTestId('not-found-home')).toHaveAttribute('href', '/')
    expect(screen.queryByTestId('impressum-error')).not.toBeInTheDocument()
    expect(document.body.textContent).not.toContain('missing-board')
  })

  it('renders a nonexistent and a foreign or private board identically', async () => {
    const notFound = { error: { key: 'not_found', message: 'Not found.' } }

    mockFetch(notFound, 404)
    const missing = renderPage('missing-board')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    const missingMarkup = missing.container.innerHTML
    missing.unmount()

    mockFetch(notFound, 404)
    const foreign = renderPage('foreign-private-board')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())

    expect(foreign.container.innerHTML).toBe(missingMarkup)
  })

  it('keeps the inline error with a retry action for other failures', async () => {
    mockFetch({ error: { key: 'server_error', message: 'Boom' } }, 500)

    renderPage('demo')

    await waitFor(() => expect(screen.getByTestId('impressum-error')).toBeInTheDocument())
    expect(screen.getByTestId('impressum-retry')).toBeInTheDocument()
    expect(screen.queryByTestId('not-found-page')).not.toBeInTheDocument()
  })
})

describe('ImpressumPage — board look (branding)', () => {
  const boardBase = {
    id: 1,
    slug: 'demo',
    name: 'Demo Board',
    legal_operator_name: 'Verein Musterstadt e.V.',
    legal_operator_address: null,
    legal_operator_email: null,
    legal_operator_notice: null,
  }

  it('applies the board colours and logo on the impressum page', async () => {
    mockFetch(
      {
        board: {
          ...boardBase,
          primary_color: '#00AA55',
          secondary_color: '#112233',
          logo_url: 'https://example.com/impressum-logo.png',
        },
      },
      200,
    )

    const { container } = renderPage('demo')
    await screen.findByText('Verein Musterstadt e.V.')

    const branded = container.querySelector('[style*="--vp-primary"]') as HTMLElement
    expect(branded.style.getPropertyValue('--vp-primary')).toBe('#00AA55')
    expect(
      container.querySelectorAll('img[src="https://example.com/impressum-logo.png"]').length,
    ).toBeGreaterThan(0)
  })

  it('applies no board colours or logo when the plan withholds them (Free)', async () => {
    mockFetch(
      { board: { ...boardBase, primary_color: null, secondary_color: null, logo_url: null } },
      200,
    )

    const { container } = renderPage('demo')
    await screen.findByText('Verein Musterstadt e.V.')

    expect(container.querySelector('[style*="--vp-primary"]')).toBeNull()
    expect(container.querySelectorAll('img').length).toBe(0)
  })
})

describe('ImpressumPage — edition-specific links', () => {
  const board = {
    id: 1,
    slug: 'demo',
    name: 'Demo Board',
    legal_operator_name: 'Verein Musterstadt e.V.',
    legal_operator_address: null,
    legal_operator_email: null,
    legal_operator_notice: null,
    show_badge: true,
  }

  afterEach(() => {
    setEdition('self-host')
  })

  it('community: no Votepit privacy notice and the badge points to the public project', async () => {
    setEdition('self-host')
    mockFetch({ board }, 200)

    renderPage('demo')
    await screen.findByText('Verein Musterstadt e.V.')

    expect(screen.queryByTestId('impressum-privacy-section')).not.toBeInTheDocument()
    expect(screen.getByTestId('board-powered-by-link')).toHaveAttribute(
      'href',
      'https://github.com/silvio-l/Votepit',
    )
  })

  it('cloud: keeps the Votepit privacy notice and the badge points to the marketing site', async () => {
    setEdition('cloud')
    mockFetch({ board }, 200)

    renderPage('demo')
    await screen.findByText('Verein Musterstadt e.V.')

    expect(screen.getByTestId('impressum-privacy-section')).toBeInTheDocument()
    expect(screen.getByTestId('impressum-privacy-link')).toHaveAttribute(
      'href',
      'https://votepit.com/de/datenschutz', // export-ok: cloud-origin
    )
    expect(screen.getByTestId('board-powered-by-link')).toHaveAttribute(
      'href',
      'https://votepit.com', // export-ok: cloud-origin
    )
  })
})
