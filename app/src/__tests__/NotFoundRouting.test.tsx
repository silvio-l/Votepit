/**
 * Routing decisions for the not-found page (App.tsx + BoardPage + RoadmapPage).
 *
 * - An unknown single segment (a board in self-host, an account in cloud) shows
 *   the shared global not-found page, not a separate "board not found" state.
 * - A nonexistent board and a board of a foreign account are answered with the
 *   same 404, so the rendered page must be byte-identical (no tenant enumeration).
 * - Multi-segment unknown paths keep using the catch-all route.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../App'
import { setAccountSlug } from '../lib/accountContext'

const NOT_FOUND_TITLE = 'Hier ist gerade nichts'

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

/**
 * Bootstrap answers with the given routing mode. Any board-scoped request
 * whose URL contains "unknown" or "foreign" gets the same 404 body, which is
 * the server's answer for both a missing board and a foreign one. The default
 * board lookup answers with `defaultBoard404Key` (no_board for an account
 * without a public board, http_error for an unknown account).
 */
function mockApi(routingMode: 'self-host' | 'cloud', defaultBoard404Key = 'http_error') {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input: RequestInfo | URL) => {
    const url = typeof input === 'string' ? input : input.toString()
    if (url.includes('/api/bootstrap')) {
      return jsonResponse({ csrf_token: 'test-csrf', user: null, routing_mode: routingMode })
    }
    if (url.includes('/board/default')) {
      return jsonResponse({ error: { key: defaultBoard404Key, message: 'Not found' } }, 404)
    }
    if (url.includes('unknown') || url.includes('foreign')) {
      return jsonResponse({ error: { key: 'not_found', message: 'Not found' } }, 404)
    }
    return jsonResponse({})
  })
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )
}

beforeEach(() => {
  setAccountSlug(null)
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
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('not-found routing', () => {
  it('self-host: an unknown single segment shows the global not-found page, not a board state', async () => {
    mockApi('self-host')
    renderAt('/unknown-board')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(NOT_FOUND_TITLE),
    )
    expect(screen.queryByText('Board nicht gefunden')).not.toBeInTheDocument()
  })

  it('cloud: an unknown account segment shows the global not-found page', async () => {
    mockApi('cloud', 'http_error')
    renderAt('/unknown-account')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(NOT_FOUND_TITLE),
    )
  })

  it('cloud: an account without any public board also shows the global page (no existence leak)', async () => {
    mockApi('cloud', 'no_board')
    renderAt('/acme')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(NOT_FOUND_TITLE),
    )
    expect(screen.queryByText('Noch kein Board eingerichtet')).not.toBeInTheDocument()
  })

  it('a nonexistent board and a foreign board render an identical page', async () => {
    mockApi('cloud')
    const { unmount } = renderAt('/acme/unknown-board')
    await waitFor(() => expect(screen.getByTestId('not-found-page')).toBeInTheDocument())
    const missing = screen.getByTestId('not-found-page').outerHTML
    unmount()

    mockApi('cloud')
    renderAt('/acme/foreign-board')
    await waitFor(() => expect(screen.getByTestId('not-found-page')).toBeInTheDocument())
    const foreign = screen.getByTestId('not-found-page').outerHTML

    expect(foreign).toBe(missing)
  })

  it('multi-segment unknown paths keep using the catch-all global page', async () => {
    mockApi('self-host')
    renderAt('/one/two/three/four')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(NOT_FOUND_TITLE),
    )
    expect(screen.getByTestId('not-found-home')).toHaveAttribute('href', '/')
  })

  it('reduced motion switches the illustration motion off on the routed page', async () => {
    vi.stubGlobal(
      'matchMedia',
      vi.fn((query: string) => ({
        matches: query.includes('prefers-reduced-motion'),
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    )
    mockApi('self-host')
    renderAt('/unknown-board')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-page')).toHaveAttribute('data-motion', 'off'),
    )
  })
})
