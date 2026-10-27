/**
 * RTL tests for DiscoverPage — public, cross-tenant board discovery
 * (GET /discover, BoardDiscoveryAction).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  - loading state before the discovery request resolves
 *  - rendered list (name, account-scoped link, idea count) from the mocked API
 *  - pagination controls appear only when there is more than one page
 *  - empty state when there are no public boards
 *  - error state on a failed request, with a working retry
 *  - reachable without auth (bootstrap user: null never redirects)
 *  - Cloud edition redirects out to votepit.com/discover instead of rendering
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setEdition } from '../lib/edition'
import { setFeatures } from '../lib/features'
import DiscoverPage from '../pages/DiscoverPage'

const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

function mockFetch(discoveryResponses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    if (url.includes('/api/bootstrap')) {
      return new Response(JSON.stringify(ANON_BOOTSTRAP), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    const r = discoveryResponses[callIndex] ?? discoveryResponses[discoveryResponses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderDiscoverPage() {
  return render(
    <MemoryRouter initialEntries={['/discover']}>
      <Routes>
        <Route path="/discover" element={<DiscoverPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  setEdition('self-host')
})

describe('DiscoverPage — loading', () => {
  it('shows a loading indicator before the discovery request resolves', () => {
    mockFetch([{ body: { ok: true, boards: [], total: 0, page: 1, limit: 24 } }])

    renderDiscoverPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('DiscoverPage — board list', () => {
  it('renders public boards from the API as links into their account/board', async () => {
    mockFetch([
      {
        body: {
          ok: true,
          boards: [
            {
              slug: 'feedback',
              name: 'Feedback Board',
              account_slug: 'acme',
              idea_count: 3,
              vote_count: 12,
            },
            {
              slug: 'ideas',
              name: 'Idea Box',
              account_slug: 'other-co',
              idea_count: 0,
              vote_count: 0,
            },
          ],
          total: 2,
          page: 1,
          limit: 24,
        },
      },
    ])

    renderDiscoverPage()

    await waitFor(() => expect(screen.getByText('Feedback Board')).toBeInTheDocument())
    expect(screen.getByText('Idea Box')).toBeInTheDocument()

    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/acme/feedback')
    expect(hrefs).toContain('/other-co/ideas')
  })

  it('does not require authentication — an anon visitor sees the list, no redirect', async () => {
    mockFetch([
      {
        body: {
          ok: true,
          boards: [
            {
              slug: 'feedback',
              name: 'Feedback Board',
              account_slug: 'acme',
              idea_count: 1,
              vote_count: 0,
            },
          ],
          total: 1,
          page: 1,
          limit: 24,
        },
      },
    ])

    renderDiscoverPage()

    await waitFor(() => expect(screen.getByText('Feedback Board')).toBeInTheDocument())
    expect(screen.queryByTestId('login-page')).not.toBeInTheDocument()
  })
})

describe('DiscoverPage — empty state', () => {
  it('shows an empty-state message when there are no public boards', async () => {
    mockFetch([{ body: { ok: true, boards: [], total: 0, page: 1, limit: 24 } }])

    renderDiscoverPage()

    await waitFor(() =>
      expect(screen.getByText(/Noch keine öffentlichen Boards/i)).toBeInTheDocument(),
    )
  })
})

describe('DiscoverPage — pagination', () => {
  it('shows pagination controls only when there is more than one page', async () => {
    mockFetch([
      {
        body: {
          ok: true,
          boards: [
            {
              slug: 'feedback',
              name: 'Feedback Board',
              account_slug: 'acme',
              idea_count: 1,
              vote_count: 0,
            },
          ],
          total: 50,
          page: 1,
          limit: 24,
        },
      },
    ])

    renderDiscoverPage()

    await waitFor(() => expect(screen.getByText('Feedback Board')).toBeInTheDocument())
    expect(screen.getByRole('navigation', { name: /Seite 1 von 3/i })).toBeInTheDocument()
  })

  it('requests the next page and renders its boards on click', async () => {
    mockFetch([
      {
        body: {
          ok: true,
          boards: [
            {
              slug: 'page-one',
              name: 'Page One Board',
              account_slug: 'acme',
              idea_count: 1,
              vote_count: 0,
            },
          ],
          total: 50,
          page: 1,
          limit: 24,
        },
      },
      {
        body: {
          ok: true,
          boards: [
            {
              slug: 'page-two',
              name: 'Page Two Board',
              account_slug: 'acme',
              idea_count: 2,
              vote_count: 0,
            },
          ],
          total: 50,
          page: 2,
          limit: 24,
        },
      },
    ])

    renderDiscoverPage()
    await waitFor(() => expect(screen.getByText('Page One Board')).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /2/ }))

    await waitFor(() => expect(screen.getByText('Page Two Board')).toBeInTheDocument())
  })
})

describe('DiscoverPage — error state', () => {
  it('shows an error message and retries on demand', async () => {
    let callIndex = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ANON_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      callIndex++
      if (callIndex === 1) {
        return new Response(JSON.stringify({ error: { key: 'http_error', message: 'boom' } }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(
        JSON.stringify({
          ok: true,
          boards: [
            {
              slug: 'recovered',
              name: 'Recovered Board',
              account_slug: 'acme',
              idea_count: 0,
              vote_count: 0,
            },
          ],
          total: 1,
          page: 1,
          limit: 24,
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      )
    })

    renderDiscoverPage()

    await waitFor(() => expect(screen.getByText(/Fehler beim Laden/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Erneut versuchen/i }))

    await waitFor(() => expect(screen.getByText('Recovered Board')).toBeInTheDocument())
  })
})

describe('DiscoverPage — Cloud edition', () => {
  afterEach(() => {
    setEdition('self-host')
    setFeatures(undefined)
  })

  it('redirects to the marketing site instead of rendering the in-app list', async () => {
    mockFetch([{ body: { ok: true, boards: [], total: 0, page: 1, limit: 24 } }])
    setEdition('cloud')
    const replaceSpy = vi.fn()
    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, replace: replaceSpy },
    })

    renderDiscoverPage()

    await waitFor(() => expect(replaceSpy).toHaveBeenCalledWith('https://votepit.com/discover'))
    expect(screen.queryByText(/Wo Stimmen etwas bewegen/i)).not.toBeInTheDocument()

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })

  it('redirects to the extension-configured marketing URL when the bootstrap feature is set', async () => {
    mockFetch([{ body: { ok: true, boards: [], total: 0, page: 1, limit: 24 } }])
    setEdition('cloud')
    setFeatures({
      board_smtp: false,
      legal_links: null,
      marketing_discover_url: 'https://staging-preview.example.test/discover',
    })
    const replaceSpy = vi.fn()
    const originalLocation = window.location
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...originalLocation, replace: replaceSpy },
    })

    renderDiscoverPage()

    await waitFor(() =>
      expect(replaceSpy).toHaveBeenCalledWith('https://staging-preview.example.test/discover'),
    )

    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation })
  })
})
