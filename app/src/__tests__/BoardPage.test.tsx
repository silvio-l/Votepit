/**
 * RTL tests for BoardPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made.
 * The bootstrap() call is also mocked to return an anonymous session.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import * as api from '../lib/api'
import BoardPage from '../pages/BoardPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

function makeIdea(
  overrides: Partial<{
    id: number
    title: string
    body: string
    status: string
    score_cache: number
    up_count: number
    down_count: number
    comment_count: number
    created_at: string
  }> = {},
) {
  return {
    id: overrides.id ?? 1,
    board_id: 1,
    author_id: 1,
    title: overrides.title ?? 'Testidee',
    body: overrides.body ?? 'Beschreibung der Idee.',
    status: overrides.status ?? 'open',
    score_cache: overrides.score_cache ?? 5,
    up_count: overrides.up_count ?? 6,
    down_count: overrides.down_count ?? 1,
    comment_count: overrides.comment_count ?? 2,
    created_at: overrides.created_at ?? '2025-06-01 10:00:00',
    updated_at: '2025-06-01 10:00:00',
  }
}

function makeBoardResponse(
  ideas: ReturnType<typeof makeIdea>[] = [],
  overrides: { is_authenticated?: boolean } = {},
) {
  return {
    board: { id: 1, slug: 'demo', name: 'Demo Board', intro: 'Willkommen!' },
    ideas,
    stats: { weekly_votes: 0, weekly_new_ideas: 0, avg_consensus: 0 },
    active_status: null,
    active_sort: 'newest',
    page: 1,
    total_pages: 1,
    is_authenticated: overrides.is_authenticated ?? false,
  }
}

/**
 * Sets up fetch mock for a test.
 *
 * Sequence:
 *   1st call: /api/bootstrap
 *   2nd call: /{boardSlug} (board data)
 */
function mockFetch(boardResponse: object) {
  const responses = [JSON.stringify(BOOTSTRAP_RESPONSE), JSON.stringify(boardResponse)]
  let callIndex = 0

  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const body = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(body, {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function mockFetchNotFound() {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    return new Response(
      JSON.stringify({ error: { key: 'not_found', message: 'Board nicht gefunden.' } }),
      { status: 404, headers: { 'Content-Type': 'application/json' } },
    )
  })
}

function renderBoardPage(slug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/${slug}`]}>
      <Routes>
        <Route path="/:boardSlug" element={<BoardPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('BoardPage', () => {
  it('renders board name from API response', async () => {
    mockFetch(makeBoardResponse([makeIdea()]))
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )
  })

  it('renders idea title, score, and status in the list', async () => {
    mockFetch(
      makeBoardResponse([
        makeIdea({ title: 'Dark mode support', score_cache: 42, status: 'planned' }),
      ]),
    )
    renderBoardPage()

    // Title visible in the list row (also in hero, so use getAllByText)
    await waitFor(() => expect(screen.getAllByText('Dark mode support').length).toBeGreaterThan(0))
    // Score (may appear multiple times — hero + list row both show it)
    expect(screen.getAllByText('42').length).toBeGreaterThan(0)
    // Status badge (may appear in hero + list row)
    expect(screen.getAllByText('Geplant').length).toBeGreaterThan(0)
  })

  it('renders FeaturedIdeaCard hero when ideas are present', async () => {
    mockFetch(makeBoardResponse([makeIdea({ title: 'Top Idee' })]))
    renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('featured-idea')).toBeInTheDocument())
    // "Top-Idee" label inside the card
    expect(screen.getByText('Top-Idee')).toBeInTheDocument()
  })

  it('passes weekly stats from the API into the FeaturedIdeaCard', async () => {
    mockFetch({
      ...makeBoardResponse([makeIdea({ title: 'Top Idee' })]),
      stats: { weekly_votes: 312, weekly_new_ideas: 18, avg_consensus: 92 },
    })
    renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('featured-idea')).toBeInTheDocument())
    expect(screen.getByText('312')).toBeInTheDocument()
    expect(screen.getByText('neue Stimmen')).toBeInTheDocument()
    expect(screen.getByText('18')).toBeInTheDocument()
    expect(screen.getByText('neue Ideen')).toBeInTheDocument()
  })

  it('does NOT render FeaturedIdeaCard when list is empty', async () => {
    mockFetch(makeBoardResponse([]))
    renderBoardPage()

    // Wait for load to complete (empty state appears)
    await waitFor(() => expect(screen.getByText('Noch keine Ideen')).toBeInTheDocument())
    expect(screen.queryByTestId('featured-idea')).not.toBeInTheDocument()
  })

  it('renders EmptyState for an empty board', async () => {
    mockFetch(makeBoardResponse([]))
    renderBoardPage()

    await waitFor(() => expect(screen.getByText('Noch keine Ideen')).toBeInTheDocument())
    expect(screen.getByText(/Sei die Erste oder der Erste/)).toBeInTheDocument()
  })

  it('maps in_progress backend status to "In Arbeit" badge', async () => {
    mockFetch(makeBoardResponse([makeIdea({ status: 'in_progress' })]))
    renderBoardPage()

    // Badge may appear in hero + list row → use getAllByText
    await waitFor(() => expect(screen.getAllByText('In Arbeit').length).toBeGreaterThan(0))
  })

  it('shows error state when board is not found (404)', async () => {
    mockFetchNotFound()
    renderBoardPage('unknown-board')

    await waitFor(() => expect(screen.getByText('Board nicht gefunden')).toBeInTheDocument())
  })

  it('logs out: clicking "Abmelden" calls logout and navigates to /login', async () => {
    // Authenticated board response → Header renders the "Abmelden" button.
    mockFetch(makeBoardResponse([makeIdea()], { is_authenticated: true }))
    const logoutSpy = vi.spyOn(api, 'logout').mockResolvedValue({ ok: true })

    render(
      <MemoryRouter initialEntries={['/demo']}>
        <Routes>
          <Route path="/:boardSlug" element={<BoardPage />} />
          <Route path="/login" element={<div>Login-Seite</div>} />
        </Routes>
      </MemoryRouter>,
    )

    const logoutButton = await screen.findByRole('button', { name: 'Abmelden' })
    await userEvent.click(logoutButton)

    // Observable behaviour: logout request fired AND navigation landed on /login.
    expect(logoutSpy).toHaveBeenCalledTimes(1)
    await waitFor(() => expect(screen.getByText('Login-Seite')).toBeInTheDocument())
  })

  it('shows multiple ideas in the list', async () => {
    mockFetch(
      makeBoardResponse([
        makeIdea({ id: 1, title: 'Idee Alpha' }),
        makeIdea({ id: 2, title: 'Idee Beta' }),
        makeIdea({ id: 3, title: 'Idee Gamma' }),
      ]),
    )
    renderBoardPage()

    await waitFor(() => expect(screen.getAllByText('Idee Alpha').length).toBeGreaterThan(0))
    expect(screen.getAllByText('Idee Beta').length).toBeGreaterThan(0)
    expect(screen.getAllByText('Idee Gamma').length).toBeGreaterThan(0)
  })

  /**
   * AC4 — Sort selection is preserved across status-filter and pagination changes.
   *
   * Steps:
   *  1. Page loads with default sort (newest).
   *  2. User clicks "Top" sort tab → API called with sort=top.
   *  3. User clicks "Offen" status filter → API called with sort=top (sort preserved).
   *  4. User clicks "Nächste Seite" (page 2) → API called with sort=top (sort preserved).
   */
  it('AC4: chosen sort is preserved across status-filter and pagination changes', async () => {
    const user = userEvent.setup()

    // Board response with total_pages = 3 so pagination is visible.
    const multiPageResponse = {
      ...makeBoardResponse([makeIdea()]),
      total_pages: 3,
    }

    // Track every fetch URL (after the bootstrap call).
    const fetchedUrls: string[] = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      fetchedUrls.push(url)
      // First call is /api/bootstrap; all subsequent calls return the board response.
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(multiPageResponse), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderBoardPage()

    // Wait for initial board load (default sort=newest, no status).
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    // 2. Click "Top" sort tab.
    const topTab = screen.getByRole('tab', { name: 'Top' })
    await user.click(topTab)

    // Wait for the API call with sort=top.
    await waitFor(() => {
      const boardCalls = fetchedUrls.filter((u) => !u.includes('/api/'))
      expect(boardCalls.some((u) => u.includes('sort=top'))).toBe(true)
    })

    // Assert SortTabs shows "Top" as selected.
    expect(topTab).toHaveAttribute('aria-selected', 'true')

    // 3. Click "Offen" status filter → sort must stay top.
    const openFilterBtn = screen.getByRole('button', { name: 'Offen' })
    await user.click(openFilterBtn)

    await waitFor(() => {
      const boardCalls = fetchedUrls.filter((u) => !u.includes('/api/'))
      // The most recent board call must carry both sort=top and status=open.
      const withStatusAndSort = boardCalls.filter(
        (u) => u.includes('sort=top') && u.includes('status=open'),
      )
      expect(withStatusAndSort.length).toBeGreaterThan(0)
    })

    // "Top" tab must still be marked as selected.
    expect(screen.getByRole('tab', { name: 'Top' })).toHaveAttribute('aria-selected', 'true')

    // 4. Click "Nächste Seite" → sort + status preserved.
    const nextPageBtn = screen.getByRole('button', { name: 'Nächste Seite' })
    await user.click(nextPageBtn)

    await waitFor(() => {
      const boardCalls = fetchedUrls.filter((u) => !u.includes('/api/'))
      // Page-2 call must still carry sort=top.
      const withSortOnPage2 = boardCalls.filter(
        (u) => u.includes('sort=top') && u.includes('page=2'),
      )
      expect(withSortOnPage2.length).toBeGreaterThan(0)
    })

    // "Top" tab stays selected after page change.
    expect(screen.getByRole('tab', { name: 'Top' })).toHaveAttribute('aria-selected', 'true')
  })
})
