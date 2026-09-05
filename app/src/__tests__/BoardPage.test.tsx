/**
 * RTL tests for BoardPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made.
 * The bootstrap() call is also mocked to return an anonymous session.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useSearchParams } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setAccountSlug } from '../lib/accountContext'
import * as api from '../lib/api'
import { setEdition } from '../lib/edition'
import BoardPage from '../pages/BoardPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

const OWNER_USER = {
  id: 1,
  is_admin: false,
  is_operator: false,
  has_password: true,
  totp_enabled: false,
  avatar_url: null,
  profile_visible: false,
  memberships: [{ account_slug: 'demo', role: 'owner' as const }],
}

const OWNER_BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: OWNER_USER }

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
    my_vote: 'up' | 'down' | 'none'
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
    my_vote: overrides.my_vote ?? 'none',
  }
}

function makeBoardResponse(
  ideas: ReturnType<typeof makeIdea>[] = [],
  overrides: {
    is_authenticated?: boolean
    show_badge?: boolean
    primary_color?: string | null
    secondary_color?: string | null
    logo_url?: string | null
  } = {},
) {
  return {
    board: {
      id: 1,
      slug: 'demo',
      name: 'Demo Board',
      intro: 'Willkommen!',
      show_badge: overrides.show_badge ?? true,
      primary_color: overrides.primary_color ?? null,
      secondary_color: overrides.secondary_color ?? null,
      logo_url: overrides.logo_url ?? null,
    },
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

/**
 * Sets up fetch mock for tests that also cast a vote (or hit a poll tick):
 *
 * Sequence:
 *   1st call: /api/bootstrap
 *   2nd call: /{boardSlug} (initial board data)
 *   3rd+ call: whatever `rest` provides in order, falling back to the last one.
 */
function mockFetchWithFollowUps(boardResponse: object, ...rest: object[]) {
  const responses = [
    JSON.stringify(BOOTSTRAP_RESPONSE),
    JSON.stringify(boardResponse),
    ...rest.map((r) => JSON.stringify(r)),
  ]
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

afterEach(() => {
  setAccountSlug(null)
  setEdition('self-host')
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

  it('renders Markdown-lite in the excerpt shown in list/hero previews, not raw syntax', async () => {
    mockFetch(
      makeBoardResponse([
        makeIdea({ title: 'Top Idee', body: 'gfdhsdghfdg **gfdshsgfh** and `code` too' }),
      ]),
    )
    const { container } = renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('featured-idea')).toBeInTheDocument())
    const bolds = container.querySelectorAll('strong')
    expect(Array.from(bolds).some((el) => el.textContent === 'gfdshsgfh')).toBe(true)
    const codes = container.querySelectorAll('code')
    expect(Array.from(codes).some((el) => el.textContent === 'code')).toBe(true)
    expect(container.textContent).not.toContain('**')
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
    expect(screen.getByText('+312')).toBeInTheDocument()
    expect(screen.getByText('Stimmen abgegeben')).toBeInTheDocument()
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
    expect(screen.getByText(/die erste Idee bringt das Board in Gang/i)).toBeInTheDocument()
  })

  it('shows a No-Results empty state (not the First-Use CTA) when a status filter matches nothing', async () => {
    const user = userEvent.setup()

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      const ideas = url.includes('status=declined') ? [] : [makeIdea()]
      return new Response(JSON.stringify(makeBoardResponse(ideas)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    await user.click(screen.getByRole('button', { name: 'Abgelehnt' }))

    await waitFor(() =>
      expect(screen.getByText('Keine Ideen mit diesem Status')).toBeInTheDocument(),
    )
    expect(screen.queryByText('Noch keine Ideen')).not.toBeInTheDocument()

    const resetBtn = screen.getByRole('button', { name: 'Alle Ideen zeigen' })
    await user.click(resetBtn)

    await waitFor(() =>
      expect(screen.queryByText('Keine Ideen mit diesem Status')).not.toBeInTheDocument(),
    )
  })

  it('maps in_progress backend status to "In Arbeit" badge', async () => {
    mockFetch(makeBoardResponse([makeIdea({ status: 'in_progress' })]))
    renderBoardPage()

    // Badge may appear in hero + list row → use getAllByText
    await waitFor(() => expect(screen.getAllByText('In Arbeit').length).toBeGreaterThan(0))
  })

  it('carries the full /{accountSlug}/{boardSlug}/idea/{id} path in the anon vote-redirect r= param (cloud mode)', async () => {
    // Regression: BoardPage's VotableRow built `returnTo` as a raw
    // `/${boardSlug}/idea/${id}` string, skipping accountPath() — unlike the
    // sibling `href` right next to it. In cloud mode that dropped the
    // account segment entirely, so an anonymous voter's login redirect (and
    // the URL they landed back on) collapsed the board slug into the
    // account-slug position, e.g. /stageing-test/stage/idea/26 became the
    // malformed, 404-ing /stage/idea/26.
    setEdition('cloud')
    setAccountSlug('stageing-test')
    mockFetch(makeBoardResponse([makeIdea({ id: 26 })]))

    function LoginProbe() {
      const [params] = useSearchParams()
      return <div data-testid="login-r">{params.get('r')}</div>
    }

    render(
      <MemoryRouter initialEntries={['/stageing-test/demo']}>
        <Routes>
          <Route path="/:accountSlug/:boardSlug" element={<BoardPage />} />
          <Route path="/login" element={<LoginProbe />} />
        </Routes>
      </MemoryRouter>,
    )

    const upvote = await screen.findByRole('button', { name: 'Dafür stimmen' })
    await userEvent.click(upvote)

    await waitFor(() =>
      expect(screen.getByTestId('login-r')).toHaveTextContent('/stageing-test/demo/idea/26'),
    )
  })

  it('shows error state when board is not found (404)', async () => {
    mockFetchNotFound()
    renderBoardPage('unknown-board')

    await waitFor(() => expect(screen.getByText('Board nicht gefunden')).toBeInTheDocument())
  })

  it('logs out: "Abmelden" in the account menu calls logout and navigates to /login', async () => {
    // Authenticated board response → Header renders the "Profil" account menu.
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

    const accountButton = await screen.findByRole('button', { name: 'Profil' })
    await userEvent.click(accountButton)
    const logoutButton = await screen.findByRole('menuitem', { name: 'Abmelden' })
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
   *  4. User clicks "Nächste Seite" (page 2) → API called with sort=top (sort preserved). // export-ok: comment-language
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

    // 4. Click "Nächste Seite" → sort + status preserved. // export-ok: comment-language
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

// ── Voter-preview toggle (owner/moderator "view as voter") ────────────────────

function mockFetchWithBootstrap(bootstrap: object, boardResponse: object) {
  const responses = [JSON.stringify(bootstrap), JSON.stringify(boardResponse)]
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

function renderBoardPageAt(entry: string) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/:boardSlug" element={<BoardPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

describe('BoardPage — voter-preview toggle', () => {
  it('does not show the toggle for an anonymous visitor', async () => {
    mockFetch(makeBoardResponse([makeIdea()]))
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByRole('switch', { name: 'Als Voter ansehen' })).not.toBeInTheDocument()
  })

  it('does not show the toggle for an authenticated visitor without a moderator/owner role', async () => {
    const nonAdminUser = { ...OWNER_USER, memberships: [] }
    mockFetchWithBootstrap(
      { csrf_token: 'test-csrf', user: nonAdminUser },
      makeBoardResponse([makeIdea()], { is_authenticated: true }),
    )
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByRole('switch', { name: 'Als Voter ansehen' })).not.toBeInTheDocument()
  })

  it('shows the toggle in the account menu for the board owner and hides the admin header links once activated', async () => {
    const user = userEvent.setup()
    mockFetchWithBootstrap(
      OWNER_BOOTSTRAP_RESPONSE,
      makeBoardResponse([makeIdea()], { is_authenticated: true }),
    )
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    // Admin-only header links are visible by default (real "as admin" view).
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()

    await user.click(await screen.findByRole('button', { name: 'Profil' }))
    await user.click(await screen.findByRole('menuitem', { name: 'Als Voter ansehen' }))

    // The board-settings nav link and the account menu's admin entries disappear;
    // the trigger itself now flags the active preview.
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voter-Vorschau beenden' })).toBeInTheDocument()

    // A way back to the admin area stays reachable from the same menu.
    await user.click(screen.getByRole('button', { name: 'Voter-Vorschau beenden' }))
    expect(screen.getByRole('menuitem', { name: 'Zur Verwaltung' })).toBeInTheDocument()

    // Toggling back restores the admin header.
    await user.click(screen.getByRole('menuitem', { name: 'Voter-Vorschau beenden' }))
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Profil' })).toBeInTheDocument()
  })

  it('starts directly in voter-preview mode when the URL carries ?view=voter (AdminPage "View board" entry point)', async () => {
    const user = userEvent.setup()
    mockFetchWithBootstrap(
      OWNER_BOOTSTRAP_RESPONSE,
      makeBoardResponse([makeIdea()], { is_authenticated: true }),
    )
    renderBoardPageAt('/demo?view=voter')

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    const trigger = screen.getByRole('button', { name: 'Voter-Vorschau beenden' })
    await user.click(trigger)
    expect(screen.getByRole('menuitem', { name: 'Zur Verwaltung' })).toBeInTheDocument()
  })
})

// ── Branding tiers: "Powered by Votepit" badge ────────────────────────────────

describe('BoardPage — "Powered by Votepit" badge (branding tiers)', () => {
  it('shows the badge when the server reports show_badge: true', async () => {
    mockFetch(makeBoardResponse([], { show_badge: true }))

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.getByText('Powered by Votepit')).toBeInTheDocument()
  })

  it('hides the badge when the server reports show_badge: false (Pro plan + hide_badge set)', async () => {
    mockFetch(makeBoardResponse([], { show_badge: false }))

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.queryByText('Powered by Votepit')).not.toBeInTheDocument()
  })
})

// ── Branding tiers: primary/secondary color + logo actually applied ──────────

describe('BoardPage — brand tokens (primary_color/secondary_color/logo_url)', () => {
  it('sets the --vp-primary/--vp-ink CSS custom properties from the API response', async () => {
    mockFetch(makeBoardResponse([], { primary_color: '#ff00aa', secondary_color: '#001122' }))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    const brandedRoot = container.querySelector('[style*="--vp-primary"]') as HTMLElement | null
    expect(brandedRoot).not.toBeNull()
    expect(brandedRoot?.style.getPropertyValue('--vp-primary')).toBe('#ff00aa')
    expect(brandedRoot?.style.getPropertyValue('--vp-ink')).toBe('#001122')
  })

  it('does not set brand CSS custom properties when the fields are null (unset/downgraded)', async () => {
    mockFetch(makeBoardResponse([]))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(container.querySelector('[style*="--vp-primary"]')).toBeNull()
  })

  it('renders the board logo exactly once (top nav only, not duplicated in the masthead)', async () => {
    mockFetch(makeBoardResponse([], { logo_url: 'https://example.com/logo.png' }))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(container.querySelectorAll('img[src="https://example.com/logo.png"]')).toHaveLength(1)
  })

  it('renders no logo when logo_url is null', async () => {
    mockFetch(makeBoardResponse([]))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(container.querySelector('img')).toBeNull()
  })

  it('replaces the top-nav Votepit wordmark with the board logo when logo_url is set', async () => {
    mockFetch(makeBoardResponse([], { logo_url: 'https://example.com/logo.png' }))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    const header = container.querySelector('header')
    expect(header?.querySelector('img[src="https://example.com/logo.png"]')).not.toBeNull()
    expect(header?.querySelector('svg')).toBeNull()
  })

  it('keeps the top-nav Votepit wordmark when logo_url is null', async () => {
    mockFetch(makeBoardResponse([]))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    const header = container.querySelector('header')
    expect(header?.querySelector('a[aria-label="Votepit – Startseite"]')).not.toBeNull()
  })

  it('shows neither the wordmark nor a logo in the top nav while board data is still loading', async () => {
    mockFetch(makeBoardResponse([], { logo_url: 'https://example.com/logo.png' }))

    const { container } = renderBoardPage()

    // Board data hasn't resolved yet — must not flash the Votepit wordmark
    // before possibly swapping to the board's own logo a moment later.
    const header = container.querySelector('header')
    expect(header?.querySelector('a[aria-label="Votepit – Startseite"]')).toBeNull()
    expect(header?.querySelector('img[src="https://example.com/logo.png"]')).toBeNull()

    await waitFor(() =>
      expect(
        container.querySelector('header img[src="https://example.com/logo.png"]'),
      ).not.toBeNull(),
    )
  })
})

describe('BoardPage — live reorder on vote', () => {
  it("re-ranks the list immediately when the viewer's own vote overtakes another idea (Top sort)", async () => {
    // Featured slot takes ideas[0]; the two list rows below it are the ones under test.
    const featured = makeIdea({ id: 1, title: 'Featured idea', score_cache: 100 })
    const higher = makeIdea({ id: 2, title: 'Currently ahead', score_cache: 4, my_vote: 'none' })
    const lower = makeIdea({
      id: 3,
      title: 'Currently behind',
      score_cache: 3,
      my_vote: 'down',
      up_count: 3,
      down_count: 1,
    })
    mockFetchWithFollowUps(
      makeBoardResponse([featured, higher, lower], { is_authenticated: true }),
      // POST vote response for "lower" flipping from down to up: +2 → score 5, overtakes "higher" (4).
      { score: 5, my_vote: 'up', up_count: 4, down_count: 0 },
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    const before = screen.getAllByRole('listitem').map((el) => el.textContent)
    expect(before[0]).toContain('Currently ahead')
    expect(before[1]).toContain('Currently behind')

    const upvoteButtons = screen.getAllByRole('button', { name: 'Dafür stimmen' })
    // Button order mirrors DOM order: [0] featured card, [1] "Currently ahead", [2] "Currently behind".
    await userEvent.click(upvoteButtons[2])

    await waitFor(() => {
      const after = screen.getAllByRole('listitem').map((el) => el.textContent)
      expect(after[0]).toContain('Currently behind')
      expect(after[1]).toContain('Currently ahead')
    })
  })

  it('promotes an idea straight into the featured slot when the vote overtakes the current featured score', async () => {
    const featured = makeIdea({ id: 1, title: 'Currently featured', score_cache: 1 })
    const middle = makeIdea({ id: 2, title: 'Middle idea', score_cache: 0 })
    const bottom = makeIdea({
      id: 3,
      title: 'Bottom idea, about to overtake',
      score_cache: 0,
      my_vote: 'none',
    })
    mockFetchWithFollowUps(
      makeBoardResponse([featured, middle, bottom], { is_authenticated: true }),
      // Upvoting "bottom" flips it from 0 to 1, tying but arriving after — so
      // strictly overtaking is guaranteed with a +2 swing instead.
      { score: 2, my_vote: 'up', up_count: 1, down_count: 0 },
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    expect(screen.getByTestId('featured-idea')).toHaveTextContent('Currently featured')

    const upvoteButtons = screen.getAllByRole('button', { name: 'Dafür stimmen' })
    // [0] featured card, [1] "Middle idea", [2] "Bottom idea, about to overtake".
    await userEvent.click(upvoteButtons[2])

    await waitFor(() => {
      expect(screen.getByTestId('featured-idea')).toHaveTextContent(
        'Bottom idea, about to overtake',
      )
    })
    const after = screen.getAllByRole('listitem').map((el) => el.textContent)
    expect(after.some((text) => text?.includes('Currently featured'))).toBe(true)
  })
})

describe('BoardPage — background vote polling', () => {
  it("polls for other voters' activity and re-ranks the list accordingly", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const featured = makeIdea({ id: 1, title: 'Featured idea', score_cache: 100 })
    const higher = makeIdea({ id: 2, title: 'Currently ahead', score_cache: 4 })
    const lower = makeIdea({ id: 3, title: 'Currently behind', score_cache: 3 })
    mockFetchWithFollowUps(
      makeBoardResponse([featured, higher, lower], { is_authenticated: true }),
      // The next poll tick: someone else's votes flipped the ranking server-side.
      makeBoardResponse([featured, { ...higher, score_cache: 1 }, { ...lower, score_cache: 6 }], {
        is_authenticated: true,
      }),
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    const before = screen.getAllByRole('listitem').map((el) => el.textContent)
    expect(before[0]).toContain('Currently ahead')

    await vi.advanceTimersByTimeAsync(20_000)

    await waitFor(() => {
      const after = screen.getAllByRole('listitem').map((el) => el.textContent)
      expect(after[0]).toContain('Currently behind')
      expect(after[1]).toContain('Currently ahead')
    })

    vi.useRealTimers()
  })
})
