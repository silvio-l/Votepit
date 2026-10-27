/**
 * RTL tests for IdeaDetailPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * bootstrap() is also mocked to seed the CSRF token and anon session.
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { _resetTranslationCachesForTests } from '../hooks/useIdeaTranslation'
import IdeaDetailPage from './IdeaDetailPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }
const BOOTSTRAP_AUTHED = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: false, memberships: [] },
}
/** Same as BOOTSTRAP_AUTHED but with the translate endpoints enabled (idea-comment-translation). */
const BOOTSTRAP_AUTHED_TRANSLATABLE = {
  ...BOOTSTRAP_AUTHED,
  translation_available: true,
}

function makeIdeaDetailResponse(
  overrides: {
    title?: string
    body?: string
    status?: string
    score_cache?: number
    up_count?: number
    down_count?: number
    comment_count?: number
    view_count?: number
    comments?: Array<{
      id: number
      idea_id: number
      author_id: number
      body: string
      created_at: string
    }>
    is_authenticated?: boolean
    idea_created_at?: string
    translation_enabled?: boolean
    anonymous_voting_enabled?: boolean
    legal_operator_name?: string | null
    legal_operator_address?: string | null
  } = {},
) {
  const commentCount = overrides.comment_count ?? 3
  const comments =
    overrides.comments ??
    Array.from({ length: commentCount }, (_, i) => ({
      id: i + 1,
      idea_id: 42,
      author_id: 2,
      body: `Kommentar ${i + 1}`,
      created_at: '2025-06-01 10:00:00',
    }))

  return {
    board: {
      id: 1,
      slug: 'demo',
      name: 'Demo Board',
      translation_enabled: overrides.translation_enabled ?? false,
      anonymous_voting_enabled: overrides.anonymous_voting_enabled ?? false,
      legal_operator_name: overrides.legal_operator_name ?? null,
      legal_operator_address: overrides.legal_operator_address ?? null,
    },
    idea: {
      id: 42,
      board_id: 1,
      author_id: 1,
      title: overrides.title ?? 'Eine tolle Feature-Idee',
      body: overrides.body ?? 'Hier steht der vollständige Beschreibungstext der Idee.',
      status: overrides.status ?? 'open',
      score_cache: overrides.score_cache ?? 7,
      view_count: overrides.view_count ?? 12,
      up_count: overrides.up_count ?? 9,
      down_count: overrides.down_count ?? 2,
      comment_count: commentCount,
      created_at: overrides.idea_created_at ?? '2025-06-01 10:00:00',
      updated_at: '2025-06-01 10:00:00',
    },
    comments,
    is_authenticated: overrides.is_authenticated ?? false,
  }
}

/**
 * Mock fetch with two sequential responses:
 *   1. /api/bootstrap
 *   2. /{boardSlug}/ideas/{ideaId}
 */
function mockFetch(detailResponse: object, detailStatus = 200, bootstrapOverride?: object) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(bootstrapOverride ?? BOOTSTRAP_RESPONSE), status: 200 },
    { body: JSON.stringify(detailResponse), status: detailStatus },
  ]

  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(r.body, {
      status: r.status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

/**
 * Mock fetch with three sequential responses (for withdraw flow):
 *  1. /api/bootstrap  (authenticated as user id=1)
 *  2. /{boardSlug}/ideas/{ideaId}  (GET detail)
 *  3. /{boardSlug}/ideas/{ideaId}/withdraw  (POST)
 */
function mockFetchWithWithdraw(detailResponse: object, withdrawStatus = 200) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_AUTHED), status: 200 },
    { body: JSON.stringify(detailResponse), status: 200 },
    { body: JSON.stringify({ ok: true }), status: withdrawStatus },
  ]

  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(r.body, {
      status: r.status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

/**
 * Mock fetch with three sequential responses (for comment-post flow):
 *  1. /api/bootstrap  (authenticated as user id=1)
 *  2. /{boardSlug}/ideas/{ideaId}  (GET detail)
 *  3. /{boardSlug}/ideas/{ideaId}/comments  (POST)
 */
function mockFetchWithComment(
  detailResponse: object,
  commentStatus = 201,
  commentBody: object = { ok: true, id: 100 },
) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_AUTHED), status: 200 },
    { body: JSON.stringify(detailResponse), status: 200 },
    { body: JSON.stringify(commentBody), status: commentStatus },
  ]

  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(r.body, {
      status: r.status,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

/**
 * Mock fetch for the auto-translate flow (tickets 07/08). Dispatches by URL
 * rather than by call order — an authenticated bootstrap also triggers a
 * fire-and-forget GET /account/profile (own avatar) whose relative timing
 * against the translate calls isn't guaranteed, so a fixed-position array
 * (like the other mock*() helpers above) is too brittle here.
 */
function mockFetchWithTranslation(
  detailResponse: object,
  options: {
    bootstrapOverride?: object
    translateIdea?: { body: object; status?: number }
    translateComments?: { body: object; status?: number }
  } = {},
) {
  const bootstrapBody = JSON.stringify(options.bootstrapOverride ?? BOOTSTRAP_AUTHED_TRANSLATABLE)
  const detailBody = JSON.stringify(detailResponse)
  const profileBody = JSON.stringify({
    avatar_url: null,
    website_domain: null,
    x_handle: null,
    youtube_handle: null,
    github_username: null,
  })

  const spy = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = String(input)
    if (url.includes('/comments/translate')) {
      const r = options.translateComments ?? { body: { comments: [] } }
      return new Response(JSON.stringify(r.body), {
        status: r.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/translate')) {
      const r = options.translateIdea ?? { body: {} }
      return new Response(JSON.stringify(r.body), {
        status: r.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/account/profile')) {
      return new Response(profileBody, {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/bootstrap')) {
      return new Response(bootstrapBody, {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(detailBody, {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return spy
}

/**
 * Mock fetch for the anonymous-vote + notify-email flow, dispatched by URL
 * so it works regardless of how many vote/notify-email requests a test
 * makes: bootstrap, GET detail, POST vote, PATCH notify-email.
 */
function mockFetchWithAnonVoting(
  detailResponse: object,
  options: { voteBodies?: object[]; notifyEmailStatus?: number; notifyEmailBody?: object } = {},
) {
  const bootstrapBody = JSON.stringify(BOOTSTRAP_RESPONSE)
  const detailBody = JSON.stringify(detailResponse)
  const voteBodies = options.voteBodies ?? [{ score: 1, my_vote: 'up', up_count: 1, down_count: 0 }]
  let voteCallIndex = 0

  return vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    if (url.includes('/vote/notify-email')) {
      return new Response(JSON.stringify(options.notifyEmailBody ?? { ok: true }), {
        status: options.notifyEmailStatus ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/vote') && method === 'POST') {
      const body = voteBodies[voteCallIndex] ?? voteBodies[voteBodies.length - 1]
      voteCallIndex++
      return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    if (url.includes('/bootstrap')) {
      return new Response(bootstrapBody, {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(detailBody, {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderIdeaDetailPage(boardSlug = 'demo', ideaId = '42') {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/idea/${ideaId}`]}>
      <Routes>
        <Route path="/:boardSlug/idea/:ideaId" element={<IdeaDetailPage />} />
        <Route path="/:boardSlug" element={<div data-testid="board-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  // The translation hooks cache by ideaId+locale at module scope
  // (session-lifetime cache, ticket 07/08) — reset it so one test's fake
  // idea id/locale combination never leaks a cached result into another.
  _resetTranslationCachesForTests()
})

describe('IdeaDetailPage', () => {
  it('renders idea title, body, score, consensus data and status from API response', async () => {
    mockFetch(
      makeIdeaDetailResponse({
        title: 'Dark mode support',
        body: 'Als Nutzer möchte ich einen Dark Mode haben.',
        status: 'planned',
        score_cache: 15,
        up_count: 18,
        down_count: 3,
        comment_count: 5,
        view_count: 123,
      }),
    )

    renderIdeaDetailPage()

    // Title
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Dark mode support' })).toBeInTheDocument(),
    )

    // Full body text
    expect(screen.getByText('Als Nutzer möchte ich einen Dark Mode haben.')).toBeInTheDocument()

    // Up count 18 in the up vote control (score 15 is net, shown by VoteSummary)
    expect(within(screen.getByTestId('idea-vote-up')).getByText('18')).toBeInTheDocument()

    // Status badge
    expect(screen.getByText('Geplant')).toBeInTheDocument()

    // Comment count
    expect(screen.getAllByText(/5 Kommentare/).length).toBeGreaterThan(0)

    // Consensus bar: 18/(18+3) = 85.7% → Math.round → 86%
    // VoteSummary shows the approval value and its label ("Zustimmung") as separate elements.
    expect(screen.getByText('Zustimmung')).toBeInTheDocument()

    // Up / Down counts visible in the vote controls
    const controls = screen.getByTestId('idea-vote-controls')
    expect(within(controls).getByText('18')).toBeInTheDocument()
    expect(within(controls).getByText('3')).toBeInTheDocument()

    // View count
    expect(screen.getByText('123 Aufrufe')).toBeInTheDocument()
  })

  it('shows a singular view label for exactly one view', async () => {
    mockFetch(makeIdeaDetailResponse({ view_count: 1 }))

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('1 Aufruf')).toBeInTheDocument())
  })

  it('renders the shared not-found page with the idea copy when API returns 404', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Idee nicht gefunden.' } }, 404)

    renderIdeaDetailPage('demo', '99999')

    await waitFor(() =>
      expect(screen.getByTestId('not-found-title')).toHaveTextContent(
        'Diese Idee ist gerade nicht hier',
      ),
    )
    expect(screen.getByTestId('not-found-message')).toHaveTextContent(
      'Vielleicht wurde sie verschoben oder entfernt.',
    )
    expect(screen.getByTestId('not-found-home')).toHaveAttribute('href', '/')
    expect(screen.getByTestId('not-found-back')).toBeInTheDocument()
    // The way back to the board is derived from the URL path, so it stays.
    expect(screen.getByTestId('idea-detail-not-found-back')).toHaveTextContent('Zurück zum Board')
    // Not the generic error state, and no alert that would announce a failure.
    expect(screen.queryByText('Idee konnte nicht geladen werden')).not.toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    // The requested id is never echoed back on the page.
    expect(document.body.textContent).not.toContain('99999')
  })

  it('renders a nonexistent and a foreign idea identically', async () => {
    const notFound = { error: { key: 'not_found', message: 'Not found.' } }

    mockFetch(notFound, 404)
    const missing = renderIdeaDetailPage('demo', '99999')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    const missingMarkup = missing.container.innerHTML
    missing.unmount()

    // The API answers a foreign idea with the same 404 (same board path, different id).
    mockFetch(notFound, 404)
    const foreign = renderIdeaDetailPage('demo', '42')
    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())

    expect(foreign.container.innerHTML).toBe(missingMarkup)
  })

  it('shows edit and withdraw entry points only for the author (AC3)', async () => {
    // author_id=1, bootstrap user=id:1 → is owner, created just now → within the 2h edit window → buttons visible
    mockFetch(
      makeIdeaDetailResponse({ idea_created_at: new Date().toISOString() }),
      200,
      BOOTSTRAP_AUTHED,
    )

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.getByRole('link', { name: 'Bearbeiten' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Idee zurückziehen/i })).toBeInTheDocument()
  })

  it('hides the edit link (but keeps withdraw) once the 2h edit window has expired', async () => {
    // author_id=1, bootstrap user=id:1 → is owner, but created 3h ago → edit window expired
    const threeHoursAgo = new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString()
    mockFetch(makeIdeaDetailResponse({ idea_created_at: threeHoursAgo }), 200, BOOTSTRAP_AUTHED)

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByRole('link', { name: 'Bearbeiten' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Idee zurückziehen/i })).toBeInTheDocument()
  })

  it('does NOT show edit/withdraw buttons for a non-owner (AC3)', async () => {
    // author_id=1, bootstrap user=null → not owner → buttons hidden
    mockFetch(makeIdeaDetailResponse())

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByRole('link', { name: 'Bearbeiten' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Idee zurückziehen/i })).not.toBeInTheDocument()
  })

  it('withdraw calls server and navigates to board (AC2)', async () => {
    // author_id=1, bootstrap user=id:1 → is owner
    mockFetchWithWithdraw(makeIdeaDetailResponse())

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Idee zurückziehen/i })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Idee zurückziehen/i }))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Idee zurückziehen' }))

    await waitFor(() => expect(screen.getByTestId('board-page')).toBeInTheDocument())
  })

  it('withdraw does nothing if the confirm dialog is dismissed', async () => {
    mockFetchWithWithdraw(makeIdeaDetailResponse())

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /Idee zurückziehen/i })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Idee zurückziehen/i }))

    const dialog = await screen.findByRole('alertdialog')
    await user.click(within(dialog).getByRole('button', { name: 'Abbrechen' }))

    await waitFor(() => expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument())
    expect(screen.queryByTestId('board-page')).not.toBeInTheDocument()
  })

  // -------------------------------------------------------------------------
  // Comments (Comment CRUD)
  // -------------------------------------------------------------------------

  it('renders existing comments from the API response', async () => {
    mockFetch(
      makeIdeaDetailResponse({
        comments: [
          {
            id: 1,
            idea_id: 42,
            author_id: 2,
            body: 'Erster Kommentar',
            created_at: '2025-06-01 10:00:00',
          },
          {
            id: 2,
            idea_id: 42,
            author_id: 3,
            body: 'Zweiter Kommentar',
            created_at: '2025-06-01 11:00:00',
          },
        ],
      }),
    )

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('Erster Kommentar')).toBeInTheDocument())
    expect(screen.getByText('Zweiter Kommentar')).toBeInTheDocument()
    expect(screen.getAllByText(/2 Kommentare/).length).toBeGreaterThan(0)
  })

  it('shows a compose box for an authenticated user and posts a comment', async () => {
    mockFetchWithComment(makeIdeaDetailResponse({ comments: [], is_authenticated: true }))

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByPlaceholderText('Schreib einen Kommentar…')).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.type(screen.getByPlaceholderText('Schreib einen Kommentar…'), 'Mein neuer Kommentar')
    await user.click(screen.getByRole('button', { name: 'Kommentieren' }))

    await waitFor(() => expect(screen.getByText('Mein neuer Kommentar')).toBeInTheDocument())
  })

  it('shows a login prompt instead of a compose box for an anonymous user', async () => {
    mockFetch(makeIdeaDetailResponse({ comments: [] }))

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByPlaceholderText('Schreib einen Kommentar…')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Melde dich an/i })).toBeInTheDocument()
  })

  it('shows an empty state when there are no comments yet', async () => {
    mockFetch(makeIdeaDetailResponse({ comments: [] }))

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText(/Noch keine Kommentare/)).toBeInTheDocument())
  })

  // -------------------------------------------------------------------------
  // Own-avatar display (profile-avatar-social) — the current user's own
  // avatar comes from the private /account/profile call and is shown next to
  // every "You" label (the idea's author line + own comments). Other authors
  // are rendered by AuthorBadge from their PUBLIC profile — anonymous by
  // default (profile-visibility), so no avatar unless they opted in.
  // -------------------------------------------------------------------------

  it('shows the own avatar image next to "You" but never next to other authors', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [
        {
          id: 1,
          idea_id: 42,
          author_id: 1, // matches BOOTSTRAP_AUTHED's user.id → "You"
          body: 'Mein eigener Kommentar',
          created_at: '2025-06-01 10:00:00',
        },
        {
          id: 2,
          idea_id: 42,
          author_id: 2, // someone else → anonymous "Voter"
          body: 'Fremder Kommentar',
          created_at: '2025-06-01 11:00:00',
        },
      ],
    })

    let callIndex = 0
    const responses = [
      { body: JSON.stringify(BOOTSTRAP_AUTHED), status: 200 },
      { body: JSON.stringify(detailResponse), status: 200 },
      {
        body: JSON.stringify({
          avatar_url: '/avatar/myfile.jpg',
          website_domain: null,
          x_handle: null,
          youtube_handle: null,
          github_username: null,
        }),
        status: 200,
      },
    ]
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('Mein eigener Kommentar')).toBeInTheDocument())
    expect(screen.getByText('Fremder Kommentar')).toBeInTheDocument()

    // The own avatar appears exactly twice: the idea's author line (author_id 1
    // is the current user in makeIdeaDetailResponse) and the own comment. The
    // other author's badge stays a silhouette — no <img> at all for them.
    await waitFor(() => {
      expect(document.querySelectorAll('img[src="/avatar/myfile.jpg"]')).toHaveLength(2)
    })
    expect(document.querySelectorAll('img')).toHaveLength(2)
    expect(screen.getAllByText('Du')).toHaveLength(2)
    expect(screen.getByText('Voter')).toBeInTheDocument()
  })

  it('renders the silhouette placeholder next to "You" when no avatar is set', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [
        {
          id: 1,
          idea_id: 42,
          author_id: 1,
          body: 'Mein Kommentar ohne Avatar',
          created_at: '2025-06-01 10:00:00',
        },
      ],
    })

    let callIndex = 0
    const responses = [
      { body: JSON.stringify(BOOTSTRAP_AUTHED), status: 200 },
      { body: JSON.stringify(detailResponse), status: 200 },
      {
        body: JSON.stringify({
          avatar_url: null,
          website_domain: null,
          x_handle: null,
          youtube_handle: null,
          github_username: null,
        }),
        status: 200,
      },
    ]
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('Mein Kommentar ohne Avatar')).toBeInTheDocument())
    expect(document.querySelector('img')).toBeNull()
  })

  // -------------------------------------------------------------------------
  // Auto-translate (idea-comment-translation, tickets 07/08)
  // -------------------------------------------------------------------------

  describe('auto-translate', () => {
    it('auto-translates the idea and its comments on language mismatch, with a working show-original toggle', async () => {
      const detailResponse = makeIdeaDetailResponse({
        title: 'Original Titel',
        body: 'Original Beschreibung.',
        is_authenticated: true,
        translation_enabled: true,
        comments: [
          {
            id: 1,
            idea_id: 42,
            author_id: 2,
            body: 'Original Kommentar',
            created_at: '2025-06-01 10:00:00',
          },
        ],
      })

      mockFetchWithTranslation(detailResponse, {
        translateIdea: {
          body: {
            translated_title: 'Translated Title',
            translated_body: 'Translated description.',
            detected_source_locale: 'en',
            cached: false,
          },
        },
        translateComments: {
          body: {
            comments: [
              {
                id: 1,
                ok: true,
                translated_body: 'Translated comment',
                detected_source_locale: 'en',
                cached: false,
              },
            ],
          },
        },
      })

      renderIdeaDetailPage()

      // Translated content shown by default, marked as machine-translated.
      await waitFor(() =>
        expect(screen.getByRole('heading', { name: 'Translated Title' })).toBeInTheDocument(),
      )
      expect(screen.getByText('Translated description.')).toBeInTheDocument()
      expect(screen.getByText('Translated comment')).toBeInTheDocument()
      expect(screen.getAllByText('Maschinell übersetzt').length).toBeGreaterThan(0)

      // Toggling "Original anzeigen" for the idea reveals the original
      // instantly (no extra fetch — see the assertion below).
      const user = userEvent.setup()
      const ideaToggle = screen.getAllByRole('button', { name: 'Original anzeigen' })[0]
      await user.click(ideaToggle)

      await waitFor(() =>
        expect(screen.getByRole('heading', { name: 'Original Titel' })).toBeInTheDocument(),
      )
      expect(screen.getByText('Original Beschreibung.')).toBeInTheDocument()
      expect(screen.getByRole('button', { name: 'Übersetzung anzeigen' })).toBeInTheDocument()

      // The comment's own toggle is independent of the idea's.
      expect(screen.getByText('Translated comment')).toBeInTheDocument()
    })

    it('renders mixed per-comment translation outcomes independently (translated / same-language / failed)', async () => {
      const detailResponse = makeIdeaDetailResponse({
        is_authenticated: true,
        translation_enabled: true,
        comments: [
          {
            id: 1,
            idea_id: 42,
            author_id: 2,
            body: 'Fremdsprachiger Kommentar',
            created_at: '2025-06-01 10:00:00',
          },
          {
            id: 2,
            idea_id: 42,
            author_id: 2,
            body: 'Bereits deutscher Kommentar',
            created_at: '2025-06-01 10:01:00',
          },
          {
            id: 3,
            idea_id: 42,
            author_id: 2,
            body: 'Kommentar mit Fehler',
            created_at: '2025-06-01 10:02:00',
          },
        ],
      })

      mockFetchWithTranslation(detailResponse, {
        translateIdea: {
          body: {
            translated_title: null,
            translated_body: 'Hier steht der vollständige Beschreibungstext der Idee.',
            detected_source_locale: 'de',
            cached: false,
          },
        },
        translateComments: {
          body: {
            comments: [
              {
                id: 1,
                ok: true,
                translated_body: 'Translated comment',
                detected_source_locale: 'en',
              },
              {
                id: 2,
                ok: true,
                translated_body: 'Bereits deutscher Kommentar',
                detected_source_locale: 'de',
              },
              { id: 3, ok: false },
            ],
          },
        },
      })

      renderIdeaDetailPage()

      // Comment 1: translated by default, marked, with its own toggle.
      await waitFor(() => expect(screen.getByText('Translated comment')).toBeInTheDocument())
      // Comment 2: already in the viewer's language — no marking, no toggle for it.
      expect(screen.getByText('Bereits deutscher Kommentar')).toBeInTheDocument()
      // Comment 3: provider failure — original text stays, non-blocking hint shown.
      expect(screen.getByText('Kommentar mit Fehler')).toBeInTheDocument()
      expect(screen.getByText('Übersetzung derzeit nicht verfügbar.')).toBeInTheDocument()

      // Exactly one "translated" marking (comment 1) — comment 2 has none.
      expect(screen.getAllByText('Maschinell übersetzt')).toHaveLength(1)

      // Toggling comment 1's own "show original" only affects comment 1.
      const user = userEvent.setup()
      const commentToggle = screen.getByRole('button', { name: 'Original anzeigen' })
      await user.click(commentToggle)

      await waitFor(() => expect(screen.getByText('Fremdsprachiger Kommentar')).toBeInTheDocument())
      expect(screen.getByText('Bereits deutscher Kommentar')).toBeInTheDocument()
      expect(screen.getByText('Kommentar mit Fehler')).toBeInTheDocument()
    })

    it('shows the original with no translation marking when the idea already matches the UI language', async () => {
      const detailResponse = makeIdeaDetailResponse({
        title: 'Eine tolle Feature-Idee',
        body: 'Hier steht der vollständige Beschreibungstext der Idee.',
        is_authenticated: true,
        translation_enabled: true,
        comments: [],
      })

      mockFetchWithTranslation(detailResponse, {
        translateIdea: {
          body: {
            translated_title: 'Eine tolle Feature-Idee',
            translated_body: 'Hier steht der vollständige Beschreibungstext der Idee.',
            detected_source_locale: 'de',
            cached: false,
          },
        },
      })

      renderIdeaDetailPage()

      await waitFor(() =>
        expect(
          screen.getByRole('heading', { name: 'Eine tolle Feature-Idee' }),
        ).toBeInTheDocument(),
      )

      expect(screen.queryByText('Maschinell übersetzt')).not.toBeInTheDocument()
      expect(screen.queryByRole('button', { name: 'Original anzeigen' })).not.toBeInTheDocument()
    })

    it('renders the original with a non-blocking hint when the translate call fails (503)', async () => {
      const detailResponse = makeIdeaDetailResponse({
        title: 'Eine tolle Feature-Idee',
        is_authenticated: true,
        translation_enabled: true,
        comments: [],
      })

      mockFetchWithTranslation(detailResponse, {
        translateIdea: {
          body: { error: { key: 'translation_unavailable', message: 'nope' } },
          status: 503,
        },
      })

      renderIdeaDetailPage()

      await waitFor(() =>
        expect(
          screen.getByRole('heading', { name: 'Eine tolle Feature-Idee' }),
        ).toBeInTheDocument(),
      )

      await waitFor(() =>
        expect(screen.getByText('Übersetzung derzeit nicht verfügbar.')).toBeInTheDocument(),
      )
      // The idea stays fully readable — no broken page, no blocking spinner.
      expect(screen.queryByText('Maschinell übersetzt')).not.toBeInTheDocument()
    })

    it('a rate-limited (429) translate call is never cached — a later retry can still succeed', async () => {
      const detailResponse = makeIdeaDetailResponse({
        title: 'Eine tolle Feature-Idee',
        is_authenticated: true,
        translation_enabled: true,
        comments: [],
      })

      const { unmount } = mockAndRender(429)
      await waitFor(() =>
        expect(screen.getByText('Übersetzung derzeit nicht verfügbar.')).toBeInTheDocument(),
      )
      unmount()

      // Re-mount the SAME idea/locale WITHOUT resetting the module-level
      // cache — a permanently-cached rate-limit failure would keep
      // showing the "unavailable" hint forever; the fix must let this
      // second attempt actually reach the (now succeeding) provider.
      mockAndRender(200, {
        translated_title: 'Translated Title',
        translated_body: 'Translated description.',
        detected_source_locale: 'en',
        cached: false,
      })

      await waitFor(() =>
        expect(screen.getByRole('heading', { name: 'Translated Title' })).toBeInTheDocument(),
      )

      function mockAndRender(
        status: number,
        body: object = { error: { key: 'rate_limited', message: 'nope' } },
      ) {
        mockFetchWithTranslation(detailResponse, { translateIdea: { body, status } })
        return renderIdeaDetailPage()
      }
    })

    it('never fires a translate request when translation_available is false', async () => {
      const detailResponse = makeIdeaDetailResponse({ comments: [] })
      const spy = mockFetchWithTranslation(detailResponse, {
        bootstrapOverride: BOOTSTRAP_AUTHED, // no translation_available field → falsy
      })

      renderIdeaDetailPage()

      await waitFor(() =>
        expect(
          screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i }),
        ).toBeInTheDocument(),
      )

      // Never .../translate or .../comments/translate — bootstrap + the
      // idea detail GET (and the fire-and-forget own-avatar fetch) only.
      expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate'))).toBe(false)
    })

    it('never fires a translate request for an anonymous voter', async () => {
      const detailResponse = makeIdeaDetailResponse({ comments: [], is_authenticated: false })
      const spy = mockFetchWithTranslation(detailResponse, {
        bootstrapOverride: BOOTSTRAP_RESPONSE, // anon
      })

      renderIdeaDetailPage()

      await waitFor(() =>
        expect(
          screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i }),
        ).toBeInTheDocument(),
      )

      expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate'))).toBe(false)
    })

    it('never fires a translate request when the board has not opted in, even for an authenticated viewer', async () => {
      const detailResponse = makeIdeaDetailResponse({
        comments: [],
        is_authenticated: true,
        translation_enabled: false,
      })
      const spy = mockFetchWithTranslation(detailResponse)

      renderIdeaDetailPage()

      await waitFor(() =>
        expect(
          screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i }),
        ).toBeInTheDocument(),
      )

      expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate'))).toBe(false)
    })
  })
})

// ── Legal board-operator disclosure (board-operator-compliance issue 03) ─────
// A voter can land here directly via a shared idea link without visiting the
// board home page first, so the disclosure repeats here too.

describe('IdeaDetailPage — board-operator footer', () => {
  it('shows "Operated by {name}" without the address when set', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      legal_operator_name: 'Acme GmbH',
      legal_operator_address: 'Musterstraße 1, 12345 Berlin',
    })
    mockFetch(detailResponse)

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.getByText('Betrieben von Acme GmbH')).toBeInTheDocument()
    // The full address stays off the footer — it belongs on the Impressum page only.
    expect(screen.queryByText('Musterstraße 1, 12345 Berlin')).not.toBeInTheDocument()
  })

  it('renders nothing when the operator name is unset (pre-gate legacy board)', async () => {
    const detailResponse = makeIdeaDetailResponse({ comments: [], legal_operator_name: null })
    mockFetch(detailResponse)

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByText(/Betrieben von/)).not.toBeInTheDocument()
  })
})

// ── Anonymous voting: contextual notify-email prompt (ADR 0004 UX follow-up) ──
// The email field is revealed only AFTER a vote is cast, and saved via its
// own PATCH request — decoupled from the vote request itself.

describe('IdeaDetailPage — anonymous voting notify-email prompt', () => {
  it('shows no email prompt before any vote is cast', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      anonymous_voting_enabled: true,
    })
    mockFetchWithAnonVoting(detailResponse)

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByLabelText(/E-Mail \(optional\)/i)).not.toBeInTheDocument()
  })

  it('reveals the email prompt right after casting a vote, and saves it via PATCH notify-email', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      anonymous_voting_enabled: true,
    })
    const spy = mockFetchWithAnonVoting(detailResponse)

    renderIdeaDetailPage()
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    expect(screen.queryByLabelText(/E-Mail \(optional\)/i)).not.toBeInTheDocument()

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /^Dafür stimmen/ }))

    const emailField = await screen.findByLabelText(/E-Mail \(optional\)/i)
    await user.type(emailField, 'voter@example.com')
    await user.click(screen.getByRole('button', { name: 'Speichern' }))

    await waitFor(() => expect(screen.getByText('E-Mail hinterlegt.')).toBeInTheDocument())

    const notifyEmailCall = spy.mock.calls.find((call) =>
      String(call[0]).includes('/vote/notify-email'),
    )
    expect(notifyEmailCall).toBeDefined()
    const init = notifyEmailCall?.[1] as RequestInit
    expect(init.method).toBe('PATCH')
    const body = JSON.parse(String(init.body))
    expect(body.notify_email).toBe('voter@example.com')

    // Collapsed into the saved state — the input itself is gone, only the
    // confirmation + the change link remain.
    expect(screen.queryByLabelText(/E-Mail \(optional\)/i)).not.toBeInTheDocument()
  })

  it('lets the voter change a previously saved email via "Ändern"', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      anonymous_voting_enabled: true,
    })
    mockFetchWithAnonVoting(detailResponse)

    renderIdeaDetailPage()
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /^Dafür stimmen/ }))
    await user.type(await screen.findByLabelText(/E-Mail \(optional\)/i), 'first@example.com')
    await user.click(screen.getByRole('button', { name: 'Speichern' }))
    await waitFor(() => expect(screen.getByText('E-Mail hinterlegt.')).toBeInTheDocument())

    await user.click(screen.getByRole('button', { name: 'Ändern' }))

    expect(await screen.findByLabelText(/E-Mail \(optional\)/i)).toBeInTheDocument()
  })

  it('shows an error and keeps the field open when saving the email fails', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      anonymous_voting_enabled: true,
    })
    mockFetchWithAnonVoting(detailResponse, {
      notifyEmailStatus: 422,
      notifyEmailBody: {
        error: { key: 'invalid_email', message: 'Please enter a valid email address.' },
      },
    })

    renderIdeaDetailPage()
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /^Dafür stimmen/ }))
    await user.type(await screen.findByLabelText(/E-Mail \(optional\)/i), 'not-an-email')
    await user.click(screen.getByRole('button', { name: 'Speichern' }))

    await waitFor(() =>
      expect(screen.getByText('Please enter a valid email address.')).toBeInTheDocument(),
    )
    expect(screen.getByLabelText(/E-Mail \(optional\)/i)).toBeInTheDocument()
  })

  it('hides the prompt again after the vote is retracted', async () => {
    const detailResponse = makeIdeaDetailResponse({
      comments: [],
      anonymous_voting_enabled: true,
    })
    mockFetchWithAnonVoting(detailResponse, {
      voteBodies: [
        { score: 1, my_vote: 'up', up_count: 1, down_count: 0 },
        { score: 0, my_vote: 'none', up_count: 0, down_count: 0 },
      ],
    })

    renderIdeaDetailPage()
    await waitFor(() =>
      expect(screen.getByRole('heading', { name: /Eine tolle Feature-Idee/i })).toBeInTheDocument(),
    )

    const user = userEvent.setup()
    const upButton = screen.getByRole('button', { name: /^Dafür stimmen/ })
    await user.click(upButton)
    await screen.findByLabelText(/E-Mail \(optional\)/i)

    // Same arrow again → server reports the retraction ('none').
    await user.click(upButton)

    await waitFor(() =>
      expect(screen.queryByLabelText(/E-Mail \(optional\)/i)).not.toBeInTheDocument(),
    )
  })
})

describe('IdeaDetailPage — board statuses (issue 03)', () => {
  it('shows the owner label of a custom status and its closed phases', async () => {
    const base = makeIdeaDetailResponse({ status: 'done', is_authenticated: true })
    mockFetch(
      {
        ...base,
        board: {
          ...base.board,
          statuses: [
            {
              key: 's1',
              category: 'done',
              category_name: 'completed',
              is_system: false,
              label_de: 'Beschlossen',
              label_en: null,
              is_hidden: false,
              allow_votes: false,
              allow_comments: false,
              idea_count: 1,
            },
          ],
        },
        idea: { ...base.idea, status_key: 's1' },
      },
      200,
      BOOTSTRAP_AUTHED,
    )

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('Beschlossen')).toBeInTheDocument())
    expect(screen.getByText('Kommentare sind in diesem Status geschlossen.')).toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: /Kommentar/ })).not.toBeInTheDocument()
    // DS VoteControl keeps the buttons focusable with aria-disabled and explains the lock in its description
    const closedVoteButtons = [
      screen.getByTestId('idea-vote-up'),
      screen.getByTestId('idea-vote-down'),
    ]
    for (const button of closedVoteButtons) {
      expect(button).toHaveAttribute('aria-disabled', 'true')
      expect(button).toHaveAccessibleDescription(/Abstimmen ist in diesem Status geschlossen/)
    }
  })
})

// ── Pre-moderation (broaden-use-cases issue 05) ───────────────────────────────

describe('IdeaDetailPage — pending approval', () => {
  it('shows the author a status-neutral "waiting for approval" notice and no vote/comment UI', async () => {
    const response = makeIdeaDetailResponse({ comments: [], is_authenticated: true })
    mockFetch(
      { ...response, idea: { ...response.idea, moderation_state: 'pending' } },
      200,
      BOOTSTRAP_AUTHED,
    )

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('Wartet auf Freigabe')).toBeInTheDocument())
    expect(screen.getByText(/Diese Idee ist noch nicht öffentlich/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Dafür stimmen' })).not.toBeInTheDocument()
    expect(screen.queryByPlaceholderText('Schreib einen Kommentar…')).not.toBeInTheDocument()
  })

  it('shows no notice for a published idea (payload without moderation_state)', async () => {
    mockFetch(
      makeIdeaDetailResponse({ comments: [], is_authenticated: true }),
      200,
      BOOTSTRAP_AUTHED,
    )

    renderIdeaDetailPage()

    await waitFor(() =>
      expect(screen.getByRole('button', { name: /^Dafür stimmen/ })).toBeInTheDocument(),
    )
    expect(screen.queryByText('Wartet auf Freigabe')).not.toBeInTheDocument()
  })
})

// ── Board setting: a reason is required for status changes (owner decision 2026-10-26) ──

describe('IdeaDetailPage — status reason setting', () => {
  /** Moderator of the board (self-host fallback: memberships[0]) — sees the status strip. */
  const MODERATOR = {
    csrf_token: 'test-csrf',
    user: {
      id: 1,
      is_admin: true,
      is_operator: false,
      memberships: [{ account_slug: 'demo', role: 'owner' }],
    },
  }

  function withReasonSetting(statusReasonRequired: boolean) {
    const base = makeIdeaDetailResponse({ status: 'open', is_authenticated: true })
    mockFetch(
      {
        ...base,
        board: { ...base.board, status_reason_required: statusReasonRequired },
        idea: { ...base.idea, allowed_next: ['in_progress'] },
      },
      200,
      MODERATOR,
    )
  }

  it('requires a reason before saving a status change when the board requires one', async () => {
    withReasonSetting(true)
    renderIdeaDetailPage()
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByTestId('idea-status-change'), 'in_progress')

    expect(await screen.findByTestId('idea-decision-save')).toBeDisabled()
    expect(
      screen.getByText('Für diese Entscheidung ist eine Begründung Pflicht.'),
    ).toBeInTheDocument()

    await user.type(screen.getByTestId('idea-decision-reason'), 'Picked up for the next sprint')
    expect(screen.getByTestId('idea-decision-save')).toBeEnabled()
  })

  it('keeps the reason optional when the board does not require one', async () => {
    withReasonSetting(false)
    renderIdeaDetailPage()
    const user = userEvent.setup()

    await user.selectOptions(await screen.findByTestId('idea-status-change'), 'in_progress')

    expect(await screen.findByTestId('idea-decision-save')).toBeEnabled()
    expect(
      screen.queryByText('Für diese Entscheidung ist eine Begründung Pflicht.'),
    ).not.toBeInTheDocument()
  })
})
