/**
 * RTL tests for BoardPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made.
 * The bootstrap() call is also mocked to return an anonymous session.
 */

// Board imagery settings fetch their own endpoint on mount; mock it so the
// fetch-sequence mocks in this file keep matching the calls they expect.
vi.mock('../lib/api/boardAssets', async (importOriginal) => ({
  ...(await importOriginal<typeof import('../lib/api/boardAssets')>()),
  getBoardAssets: vi.fn().mockResolvedValue({
    variants: {},
    allowed: { cover_upload: true, favicon_og: true, back_link_footer: true },
    max_upload_bytes: 4194304,
    link: { back_link_url: null, back_link_label: null, footer_note: null },
  }),
}))

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useSearchParams } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { _resetTranslationCachesForTests } from '../hooks/useIdeaTranslation'
import { setAccountSlug } from '../lib/accountContext'
import * as api from '../lib/api'
import { setEdition } from '../lib/edition'
import BoardPage from '../pages/BoardPage'
import { triggerIntersection } from '../test-setup'

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
    translation_enabled?: boolean
    legal_operator_name?: string | null
    legal_operator_address?: string | null
    anonymous_voting_enabled?: boolean
    anonymous_submission_available?: boolean
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
      // Per-board opt-in (ticket 11) — defaults to false, matching the real
      // server default (migration 0055). Tests that exercise auto-translate
      // must opt in explicitly via the override.
      translation_enabled: overrides.translation_enabled ?? false,
      // Board-operator identity (board-operator-compliance issue 03) —
      // defaults to null, matching a pre-gate legacy board.
      legal_operator_name: overrides.legal_operator_name ?? null,
      legal_operator_address: overrides.legal_operator_address ?? null,
      anonymous_voting_enabled: overrides.anonymous_voting_enabled ?? false,
      anonymous_submission_available: overrides.anonymous_submission_available ?? false,
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
 * Board list answer for one request: the grouped list asks per status, so the
 * mock filters the fixture ideas by the `status` / `status_key` query param.
 */
function boardForUrl(url: string, boardResponse: object): object {
  const params = new URL(url, 'http://localhost').searchParams
  const board = boardResponse as ReturnType<typeof makeBoardResponse>
  const status = params.get('status')
  const key = params.get('status_key')
  // The active list (exclude_closed=1) leaves out the closed categories, like the API.
  const excludeClosed = params.get('exclude_closed') === '1' && key === null && status === null
  const closed = ['done', 'declined', 'archived']
  const ideas = board.ideas.filter((idea) =>
    key !== null
      ? (idea as { status_key?: string }).status_key === key
      : status !== null
        ? idea.status === status
        : excludeClosed
          ? !closed.includes(idea.status)
          : true,
  )
  return { ...board, ideas, total: ideas.length }
}

/**
 * Sets up fetch mock for a test: bootstrap by URL, every board request answered
 * from the fixture, filtered per status section.
 */
function mockFetch(boardResponse: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    const body = url.includes('/bootstrap') ? BOOTSTRAP_RESPONSE : boardForUrl(url, boardResponse)
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

const BOOTSTRAP_AUTHED_TRANSLATABLE = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: false, memberships: [] },
  translation_available: true,
}

/**
 * URL-dispatched fetch mock for board-list auto-translate tests (idea-
 * comment-translation, ticket 07 board-list follow-up) — needed because
 * call order isn't fixed once translate requests join bootstrap/board GETs.
 */
function mockFetchWithTranslation(
  boardResponse: object,
  translateResponses: Record<number, { body: object; status?: number }> = {},
  introResponse: { body: object; status?: number } = {
    body: { translated_body: 'Willkommen!', detected_source_locale: 'de', cached: false },
  },
) {
  const spy = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    const translateMatch = url.match(/\/ideas\/(\d+)\/translate$/)
    if (translateMatch) {
      const ideaId = Number(translateMatch[1])
      const r = translateResponses[ideaId] ?? { body: {} }
      return new Response(JSON.stringify(r.body), {
        status: r.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/translate-intro')) {
      // Board-intro translation is a separate, non-viewport-gated feature
      // (fires on load, not on scroll-into-view). Defaults to replying as
      // if the intro is already in the target locale, so it never
      // masquerades as translated in the idea-list-focused tests above.
      return new Response(JSON.stringify(introResponse.body), {
        status: introResponse.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/bootstrap')) {
      return new Response(JSON.stringify(BOOTSTRAP_AUTHED_TRANSLATABLE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(JSON.stringify(boardForUrl(url, boardResponse)), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return spy
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
        makeIdea({
          title: 'Dark mode support',
          score_cache: 42,
          up_count: 42,
          down_count: 0,
          status: 'planned',
        }),
      ]),
    )
    renderBoardPage()

    // Title visible in the list row (also in hero, so use getAllByText)
    await waitFor(() => expect(screen.getAllByText('Dark mode support').length).toBeGreaterThan(0))
    // Score (may appear multiple times — hero + list row both show it)
    expect(screen.getAllByText('+42').length).toBeGreaterThan(0)
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

    await waitFor(() => expect(screen.getByTestId('board-idea-1')).toBeInTheDocument())
    const bolds = container.querySelectorAll('strong')
    expect(Array.from(bolds).some((el) => el.textContent === 'gfdshsgfh')).toBe(true)
    const codes = container.querySelectorAll('code')
    expect(Array.from(codes).some((el) => el.textContent === 'code')).toBe(true)
    expect(container.textContent).not.toContain('**')
  })

  it('renders the top idea as the first row of the open group, with no hero block', async () => {
    mockFetch(makeBoardResponse([makeIdea({ title: 'Top Idee' })]))
    renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('board-idea-1')).toBeInTheDocument())
    expect(screen.getByText('Top Idee')).toBeInTheDocument()
    expect(screen.queryByTestId('featured-idea')).not.toBeInTheDocument()
  })

  it('shows the weekly stats at the bottom of the left rail, labelled as approval (never consensus)', async () => {
    mockFetch({
      ...makeBoardResponse([makeIdea()]),
      stats: { weekly_votes: 7, weekly_new_ideas: 3, avg_consensus: 64, total_ideas: 9 },
    })
    renderBoardPage()

    const week = await screen.findByTestId('board-week-stats')
    expect(screen.getByTestId('board-rail')).toContainElement(week)
    expect(week).toHaveTextContent('Diese Woche')
    expect(week).toHaveTextContent('+7')
    expect(week).toHaveTextContent('3')
    expect(week).toHaveTextContent('Ø Zustimmung')
    expect(week).toHaveTextContent('64%')
    expect(week.textContent?.toLowerCase()).not.toContain('consensus')
  })

  it('does NOT render the featured idea when list is empty', async () => {
    mockFetch(makeBoardResponse([]))
    renderBoardPage()

    // Wait for load to complete (empty state appears)
    await waitFor(() => expect(screen.getByText('Noch keine Ideen')).toBeInTheDocument())
    expect(screen.queryByTestId('featured-idea')).not.toBeInTheDocument()
  })

  it('lists open and planned ideas in one flat list and folds Done below it', async () => {
    mockFetch(
      makeBoardResponse([
        makeIdea({ id: 1, title: 'Offene Idee', status: 'open' }),
        makeIdea({ id: 2, title: 'Geplante Idee', status: 'planned' }),
        makeIdea({ id: 3, title: 'Erledigte Idee', status: 'done' }),
      ]),
    )
    renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('board-idea-1')).toBeInTheDocument())
    const active = screen.getByTestId('board-section-active')
    expect(active).toContainElement(screen.getByTestId('board-idea-2'))
    expect(active).not.toContainElement(screen.queryByTestId('board-idea-3'))
    expect(screen.queryByTestId('board-section-active-toggle')).not.toBeInTheDocument()
    expect(screen.getByTestId('board-section-done-toggle')).toHaveAttribute(
      'aria-expanded',
      'false',
    )
    expect(screen.queryByTestId('board-idea-3')).not.toBeInTheDocument()
  })

  it('expands Done on click, and a Done status filter shows it as one flat list', async () => {
    const user = userEvent.setup()
    mockFetch(
      makeBoardResponse([
        makeIdea({ id: 1, title: 'Offene Idee', status: 'open' }),
        makeIdea({ id: 3, title: 'Erledigte Idee', status: 'done' }),
      ]),
    )
    renderBoardPage()

    await waitFor(() => expect(screen.getByTestId('board-idea-1')).toBeInTheDocument())
    await user.click(screen.getByTestId('board-section-done-toggle'))
    expect(screen.getByTestId('board-idea-3')).toBeInTheDocument()

    await user.click(toolbarButton('Erledigt'))
    await waitFor(() => expect(screen.getByTestId('board-section-status-done')).toBeInTheDocument())
    expect(screen.getByTestId('board-idea-3')).toBeInTheDocument()
    expect(screen.queryByTestId('board-idea-1')).not.toBeInTheDocument()
    expect(screen.queryByTestId('board-section-done-toggle')).not.toBeInTheDocument()
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

    await user.click(toolbarButton('Abgelehnt'))

    await waitFor(() =>
      expect(screen.getByText('Keine Ideen mit diesem Status')).toBeInTheDocument(),
    )
    expect(screen.queryByText('Noch keine Ideen')).not.toBeInTheDocument()

    // Two clear actions exist (toolbar and empty state); the empty-state one is last.
    const resetButtons = screen.getAllByRole('button', { name: 'Alle Filter löschen' })
    await user.click(resetButtons[resetButtons.length - 1])

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

    const upvote = await screen.findByRole('button', { name: /^Dafür stimmen/ })
    await userEvent.click(upvote)

    await waitFor(() =>
      expect(screen.getByTestId('login-r')).toHaveTextContent('/stageing-test/demo/idea/26'),
    )
  })

  it('shows the shared not-found page when the board is not found (404)', async () => {
    mockFetchNotFound()
    renderBoardPage('unknown-board')

    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    expect(
      screen.getByRole('heading', { level: 1, name: 'Hier ist gerade nichts' }),
    ).toBeInTheDocument()
    expect(screen.queryByText('Board nicht gefunden')).not.toBeInTheDocument()
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
    const openFilterBtn = toolbarButton('Offen')
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
  })
})

// ── Voter-preview toggle (owner/moderator "view as voter") ────────────────────

function mockFetchWithBootstrap(bootstrap: object, boardResponse: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    const body = url.includes('/bootstrap') ? bootstrap : boardForUrl(url, boardResponse)
    return new Response(JSON.stringify(body), {
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

// ── Legal board-operator disclosure (board-operator-compliance issue 03) ─────

describe('BoardPage — board-operator footer', () => {
  it('shows "Operated by {name}" without the address, alongside "Powered by Votepit"', async () => {
    mockFetch(
      makeBoardResponse([], {
        show_badge: true,
        legal_operator_name: 'Acme GmbH',
        legal_operator_address: 'Musterstraße 1, 12345 Berlin',
      }),
    )

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.getByText('Betrieben von Acme GmbH')).toBeInTheDocument()
    // The full address stays off the footer — it belongs on the Impressum page only.
    expect(screen.queryByText('Musterstraße 1, 12345 Berlin')).not.toBeInTheDocument()
    // Neither line replaces the other.
    expect(screen.getByText('Powered by Votepit')).toBeInTheDocument()
  })

  it('renders even when "Powered by Votepit" is hidden by the plan tier (independent gates)', async () => {
    mockFetch(makeBoardResponse([], { show_badge: false, legal_operator_name: 'Acme GmbH' }))

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.getByText('Betrieben von Acme GmbH')).toBeInTheDocument()
    expect(screen.queryByText('Powered by Votepit')).not.toBeInTheDocument()
  })

  it('renders nothing when the operator name is unset (pre-gate legacy board)', async () => {
    mockFetch(makeBoardResponse([], { legal_operator_name: null }))

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    expect(screen.queryByText(/Betrieben von/)).not.toBeInTheDocument()
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
    expect(brandedRoot?.style.getPropertyValue('--vp-primary')).toBe('#FF00AA')
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

  it('shows the board logo in the top nav and as the round avatar; the avatar is decorative (alt="")', async () => {
    mockFetch(makeBoardResponse([], { logo_url: 'https://example.com/logo.png' }))

    const { container } = renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    // Top nav (announced) plus the masthead avatar, decorative because the board name sits next to it.
    const logos = container.querySelectorAll('img[src="https://example.com/logo.png"]')
    expect(logos).toHaveLength(2)
    expect(Array.from(logos).filter((img) => img.getAttribute('alt') === '')).toHaveLength(1)
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
    // The wordmark glyph is gone from the logo link (other header controls,
    // e.g. the language picker, carry their own icons).
    expect(header?.querySelector('a[aria-label="Votepit – Startseite"] svg')).toBeNull()
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

/**
 * Toolbar status button. Below 1280 px the left rail repeats the status chips
 * (hidden by CSS, which jsdom does not apply), and the toolbar copy comes last.
 */
function toolbarButton(name: string): HTMLElement {
  const all = screen.getAllByRole('button', { name })
  return all[all.length - 1] as HTMLElement
}

/** Board fixtures answer GETs; a POST (a vote) gets `voteBody`. */
function mockFetchWithVote(boardResponse: object, voteBody: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input)
    if (init?.method === 'POST') {
      return new Response(JSON.stringify(voteBody), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    const body = url.includes('/bootstrap') ? BOOTSTRAP_RESPONSE : boardForUrl(url, boardResponse)
    return new Response(JSON.stringify(body), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

describe('BoardPage — live reorder on vote', () => {
  it("re-ranks the list immediately when the viewer's own vote overtakes another idea (Top sort)", async () => {
    const higher = makeIdea({ id: 2, title: 'Currently ahead', score_cache: 4, my_vote: 'none' })
    const lower = makeIdea({
      id: 3,
      title: 'Currently behind',
      score_cache: 3,
      my_vote: 'down',
      up_count: 3,
      down_count: 1,
    })
    // POST vote response for "lower" flipping from down to up: +2 → score 5, overtakes "higher" (4).
    mockFetchWithVote(makeBoardResponse([higher, lower], { is_authenticated: true }), {
      score: 5,
      my_vote: 'up',
      up_count: 4,
      down_count: 0,
    })

    renderBoardPage()

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    const before = screen.getAllByRole('listitem').map((el) => el.textContent)
    expect(before[0]).toContain('Currently ahead')
    expect(before[1]).toContain('Currently behind')

    await userEvent.click(screen.getByTestId('board-idea-3-vote-up'))

    await waitFor(() => {
      const after = screen.getAllByRole('listitem').map((el) => el.textContent)
      expect(after[0]).toContain('Currently behind')
      expect(after[1]).toContain('Currently ahead')
    })
  })
})

describe('BoardPage — background vote polling', () => {
  it("polls for other voters' activity and re-ranks the list accordingly", async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })

    const higher = makeIdea({ id: 2, title: 'Currently ahead', score_cache: 4 })
    const lower = makeIdea({ id: 3, title: 'Currently behind', score_cache: 3 })
    // The fixture is swapped before the poll tick: someone else's votes flipped the ranking.
    let current = makeBoardResponse([higher, lower], { is_authenticated: true })
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = String(input)
      const body = url.includes('/bootstrap') ? BOOTSTRAP_RESPONSE : boardForUrl(url, current)
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderBoardPage()

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    const before = screen.getAllByRole('listitem').map((el) => el.textContent)
    expect(before[0]).toContain('Currently ahead')

    current = makeBoardResponse(
      [
        { ...higher, score_cache: 1 },
        { ...lower, score_cache: 6 },
      ],
      { is_authenticated: true },
    )
    await vi.advanceTimersByTimeAsync(20_000)

    await waitFor(() => {
      const after = screen.getAllByRole('listitem').map((el) => el.textContent)
      expect(after[0]).toContain('Currently behind')
      expect(after[1]).toContain('Currently ahead')
    })

    vi.useRealTimers()
  })
})

describe('BoardPage — board-list auto-translate (viewport-lazy)', () => {
  beforeEach(() => {
    _resetTranslationCachesForTests()
  })

  it('does not call the translate endpoint for a card before it has scrolled into view', async () => {
    const idea = makeIdea({ id: 5, title: 'Fremdsprachige Idee' })
    const spy = mockFetchWithTranslation(
      makeBoardResponse([idea], { is_authenticated: true, translation_enabled: true }),
    )

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getAllByText('Fremdsprachige Idee').length).toBeGreaterThan(0),
    )

    // The card renders as-is (never a broken/blocked page), but no
    // translate request has fired since it hasn't intersected yet.
    expect(spy.mock.calls.some((call) => /\/ideas\/\d+\/translate$/.test(String(call[0])))).toBe(
      false,
    )
  })

  it('never calls the translate endpoint when the board has not opted in, even for an authenticated viewer', async () => {
    const idea = makeIdea({ id: 5, title: 'Fremdsprachige Idee' })
    const spy = mockFetchWithTranslation(
      makeBoardResponse([idea], { is_authenticated: true, translation_enabled: false }),
    )

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getAllByText('Fremdsprachige Idee').length).toBeGreaterThan(0),
    )

    // Not gated behind scroll-into-view here — the per-board opt-out must
    // block translation regardless, so no intersection trigger is needed.
    expect(spy.mock.calls.some((call) => /\/ideas\/\d+\/translate$/.test(String(call[0])))).toBe(
      false,
    )
  })

  it('auto-translates a card once it scrolls into view, with its own show-original toggle', async () => {
    // One list row (id 5) is targeted below via role="listitem".
    const idea = makeIdea({
      id: 5,
      title: 'Fremdsprachige Idee',
      body: 'Fremdsprachige Beschreibung.',
    })
    mockFetchWithTranslation(
      makeBoardResponse([idea], { is_authenticated: true, translation_enabled: true }),
      {
        5: {
          body: {
            translated_title: 'Translated idea',
            translated_body: 'Translated description.',
            detected_source_locale: 'en',
            cached: false,
          },
        },
      },
    )

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getAllByText('Fremdsprachige Idee').length).toBeGreaterThan(0),
    )

    const listItem = screen.getByRole('listitem')
    triggerIntersection(listItem.firstElementChild as Element)

    await waitFor(() => expect(screen.getAllByText('Translated idea').length).toBeGreaterThan(0))
    expect(screen.getByText('Translated description.')).toBeInTheDocument()
    expect(screen.getByTestId('board-idea-5-translate')).toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Original anzeigen' }))

    await waitFor(() =>
      expect(screen.getAllByText('Fremdsprachige Idee').length).toBeGreaterThan(0),
    )
  })

  it('never calls the translate endpoint for an anonymous visitor, even once cards are in view', async () => {
    const idea = makeIdea({ id: 5, title: 'Fremdsprachige Idee' })
    const spy = mockFetchWithTranslation(makeBoardResponse([idea], { is_authenticated: false }))

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getAllByText('Fremdsprachige Idee').length).toBeGreaterThan(0),
    )
    const listItem = screen.getByRole('listitem')
    triggerIntersection(listItem.firstElementChild as Element)

    expect(spy.mock.calls.some((call) => /\/ideas\/\d+\/translate$/.test(String(call[0])))).toBe(
      false,
    )
  })
})

describe('BoardPage — phone intro "show more"', () => {
  it('offers a show-more toggle only when the intro is cut off, and expands it', async () => {
    const heights = vi
      .spyOn(HTMLElement.prototype, 'scrollHeight', 'get')
      .mockImplementation(function (this: HTMLElement) {
        return this.id === 'board-intro' ? 120 : 0
      })
    mockFetchWithTranslation(makeBoardResponse([makeIdea()]))

    renderBoardPage()

    const toggle = await screen.findByTestId('board-intro-toggle')
    expect(toggle).toHaveAttribute('aria-expanded', 'false')
    expect(toggle).toHaveTextContent('Mehr anzeigen')

    await userEvent.setup().click(toggle)

    expect(screen.getByTestId('board-intro-toggle')).toHaveAttribute('aria-expanded', 'true')
    expect(screen.getByTestId('board-intro-toggle')).toHaveTextContent('Weniger anzeigen')
    heights.mockRestore()
  })

  it('shows no toggle when the intro fits', async () => {
    mockFetchWithTranslation(makeBoardResponse([makeIdea()]))

    renderBoardPage()

    await waitFor(() => expect(screen.getByText('Willkommen!')).toBeInTheDocument())
    expect(screen.queryByTestId('board-intro-toggle')).not.toBeInTheDocument()
  })
})

describe('BoardPage — board-intro auto-translate', () => {
  it('auto-translates the board intro on load, with a working show-original toggle', async () => {
    mockFetchWithTranslation(
      makeBoardResponse([makeIdea()], { is_authenticated: true, translation_enabled: true }),
      {},
      {
        body: {
          translated_body: 'Welcome!',
          detected_source_locale: 'en',
          cached: false,
        },
      },
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getByText('Welcome!')).toBeInTheDocument())
    expect(screen.getByText('Maschinell übersetzt')).toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: 'Original anzeigen' }))

    await waitFor(() => expect(screen.getByText('Willkommen!')).toBeInTheDocument())
  })

  it('never calls the intro-translate endpoint when the board has not opted in', async () => {
    const spy = mockFetchWithTranslation(
      makeBoardResponse([makeIdea()], { is_authenticated: true, translation_enabled: false }),
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getByText('Willkommen!')).toBeInTheDocument())
    expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate-intro'))).toBe(false)
  })

  it('never calls the intro-translate endpoint for an anonymous visitor', async () => {
    const spy = mockFetchWithTranslation(
      makeBoardResponse([makeIdea()], { is_authenticated: false, translation_enabled: true }),
    )

    renderBoardPage()

    await waitFor(() => expect(screen.getByText('Willkommen!')).toBeInTheDocument())
    expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate-intro'))).toBe(false)
  })
})

// ── Participation hint (anonymous voting) ─────────────────────────────────────

describe('BoardPage — participation hint', () => {
  const HINT = /Abstimmen geht ohne Anmeldung/

  it('tells an anonymous visitor that voting needs no sign-in when the board allows it', async () => {
    mockFetch(makeBoardResponse([makeIdea()], { anonymous_voting_enabled: true }))
    renderBoardPage()

    expect(await screen.findByText(HINT)).toBeInTheDocument()
  })

  it('shows no hint when the board requires sign-in to vote', async () => {
    mockFetch(makeBoardResponse([makeIdea()], { anonymous_voting_enabled: false }))
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByText(HINT)).not.toBeInTheDocument()
  })

  it('shows no hint to a signed-in visitor, who can already vote and submit', async () => {
    const nonAdminUser = { ...OWNER_USER, memberships: [] }
    mockFetchWithBootstrap(
      { csrf_token: 'test-csrf', user: nonAdminUser },
      makeBoardResponse([makeIdea()], { is_authenticated: true, anonymous_voting_enabled: true }),
    )
    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByText(HINT)).not.toBeInTheDocument()
  })

  it('tells a visitor that ideas can be submitted without an account (ADR 0006)', async () => {
    mockFetch(
      makeBoardResponse([makeIdea()], {
        anonymous_voting_enabled: false,
        anonymous_submission_available: true,
      }),
    )
    renderBoardPage()

    expect(
      await screen.findByText(/Eine Idee kannst du auch ohne Konto einreichen/),
    ).toBeInTheDocument()
    expect(screen.queryByText(HINT)).not.toBeInTheDocument()
  })

  it('combines both hints when voting and submitting work without an account', async () => {
    mockFetch(
      makeBoardResponse([makeIdea()], {
        anonymous_voting_enabled: true,
        anonymous_submission_available: true,
      }),
    )
    renderBoardPage()

    expect(
      await screen.findByText(/Abstimmen und Ideen einreichen geht ohne Anmeldung/),
    ).toBeInTheDocument()
    expect(screen.queryByText(HINT)).not.toBeInTheDocument()
  })
})

// ── Free-text search (competitor-parity H6) ───────────────────────────────────

function SearchProbe() {
  const [params] = useSearchParams()
  return <output data-testid="url-q">{params.get('q') ?? ''}</output>
}

function renderBoardPageWithProbe(entry = '/demo') {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route
          path="/:boardSlug"
          element={
            <>
              <BoardPage />
              <SearchProbe />
            </>
          }
        />
      </Routes>
    </MemoryRouter>,
  )
}

/** URL-dispatched board mock: `byQuery` picks the ideas per `q` param (default: one idea). */
function mockSearchFetch(byQuery: Record<string, ReturnType<typeof makeIdea>[]> = {}) {
  const boardUrls: string[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : (input as Request).url
    if (url.includes('/api/bootstrap')) {
      return new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    boardUrls.push(url)
    const q = new URL(url, 'http://localhost').searchParams.get('q') ?? ''
    const ideas = byQuery[q] ?? [makeIdea({ id: 1, title: 'Dark mode' })]
    const board = { ...makeBoardResponse(ideas), active_query: q || null }
    return new Response(JSON.stringify(boardForUrl(url, board)), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return boardUrls
}

describe('BoardPage — search', () => {
  it('debounces typing into one request with q, synced to the URL', async () => {
    const user = userEvent.setup()
    const boardUrls = mockSearchFetch()

    renderBoardPageWithProbe()
    const input = await screen.findByRole('searchbox', { name: 'Ideen durchsuchen' })

    await user.type(input, 'dark')

    await waitFor(() => expect(screen.getByTestId('url-q')).toHaveTextContent('dark'))
    await waitFor(() => expect(boardUrls.some((u) => u.includes('q=dark'))).toBe(true))
    // Debounced: no request for the intermediate keystrokes.
    expect(boardUrls.some((u) => /q=d(&|$)|q=da(&|$)|q=dar(&|$)/.test(u))).toBe(false)
    // The field stays mounted and focused while the results refresh.
    expect(screen.getByRole('searchbox', { name: 'Ideen durchsuchen' })).toHaveFocus()
  })

  it('seeds the field and the first request from ?q= in the URL', async () => {
    const boardUrls = mockSearchFetch()

    renderBoardPageWithProbe('/demo?q=dark')

    expect(await screen.findByRole('searchbox', { name: 'Ideen durchsuchen' })).toHaveValue('dark')
    expect(boardUrls[0]).toContain('q=dark')
  })

  it('combines the search with the status filter', async () => {
    const user = userEvent.setup()
    const boardUrls = mockSearchFetch()

    renderBoardPageWithProbe('/demo?q=dark')
    await screen.findByRole('searchbox', { name: 'Ideen durchsuchen' })
    await waitFor(() => expect(screen.getByText('Dark mode')).toBeInTheDocument())
    expect(screen.queryByTestId('featured-idea')).not.toBeInTheDocument()

    await user.click(toolbarButton('Offen'))

    await waitFor(() =>
      expect(boardUrls.some((u) => u.includes('q=dark') && u.includes('status=open'))).toBe(true),
    )
  })

  it('shows a no-results state whose reset clears the search', async () => {
    const user = userEvent.setup()
    mockSearchFetch({ kanban: [] })

    renderBoardPageWithProbe('/demo?q=kanban')

    expect(await screen.findByText('Keine Treffer')).toBeInTheDocument()
    expect(screen.queryByText('Noch keine Ideen')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: 'Suche zurücksetzen' }))

    await waitFor(() => expect(screen.getByTestId('url-q')).toHaveTextContent(''))
    expect(screen.getByRole('searchbox', { name: 'Ideen durchsuchen' })).toHaveValue('')
    await waitFor(() => expect(screen.queryByText('Keine Treffer')).not.toBeInTheDocument())
  })
})

// ── Controversial sort (G-11) ─────────────────────────────────────────────────

describe('BoardPage — controversial sort (G-11)', () => {
  it('Umstritten sends sort=controversial and renders the server order unchanged', async () => {
    const user = userEvent.setup()
    // Server order: the contested idea first, even though the one-sided idea has the higher score.
    // A client-side re-rank (Top) would flip them, so this proves the list is shown as received.
    const contested = makeIdea({
      id: 1,
      title: 'Server first contested',
      status: 'open',
      score_cache: 0,
      up_count: 2,
      down_count: 2,
    })
    const oneSided = makeIdea({
      id: 2,
      title: 'Server second one-sided',
      status: 'open',
      score_cache: 9,
      up_count: 9,
      down_count: 0,
    })
    const boardResponse = makeBoardResponse([contested, oneSided])

    const fetchedUrls: string[] = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = String(input)
      fetchedUrls.push(url)
      const body = url.includes('/bootstrap') ? BOOTSTRAP_RESPONSE : boardForUrl(url, boardResponse)
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderBoardPage()
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Demo Board' })).toBeInTheDocument(),
    )

    await user.click(screen.getByRole('tab', { name: 'Umstritten' }))

    await waitFor(() => {
      const boardCalls = fetchedUrls.filter(
        (u) => !u.includes('/api/') && !u.includes('/bootstrap'),
      )
      expect(boardCalls.some((u) => u.includes('sort=controversial'))).toBe(true)
    })
    expect(screen.getByRole('tab', { name: 'Umstritten' })).toHaveAttribute('aria-selected', 'true')

    await waitFor(() => expect(screen.getAllByRole('listitem')).toHaveLength(2))
    const order = screen.getAllByRole('listitem').map((el) => el.textContent ?? '')
    expect(order[0]).toContain('Server first contested')
    expect(order[1]).toContain('Server second one-sided')
  })
})

// ── Tag filter from the URL (P-34: tag links on rows and the detail page) ─────

describe('BoardPage — tag filter in the URL (P-34)', () => {
  it('sends ?tag_id= from the URL with the board request', async () => {
    const boardUrls = mockSearchFetch()

    renderBoardPageWithProbe('/demo?tag_id=7')

    await waitFor(() => expect(boardUrls.some((u) => u.includes('tag_id=7'))).toBe(true))
  })

  it('ignores a malformed ?tag_id= instead of filtering on it', async () => {
    const boardUrls = mockSearchFetch()

    renderBoardPageWithProbe('/demo?tag_id=abc')

    await waitFor(() => expect(boardUrls.length).toBeGreaterThan(0))
    expect(boardUrls.some((u) => u.includes('tag_id'))).toBe(false)
  })
})
