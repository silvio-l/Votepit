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
import IdeaDetailPage from './IdeaDetailPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }
const BOOTSTRAP_AUTHED = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false, is_operator: false, memberships: [] },
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
    board: { id: 1, slug: 'demo', name: 'Demo Board' },
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

    // Score in VoteWidget (font-mono-num span)
    expect(screen.getByText('15')).toBeInTheDocument()

    // Status badge
    expect(screen.getByText('Geplant')).toBeInTheDocument()

    // Comment count
    expect(screen.getAllByText(/5 Kommentare/).length).toBeGreaterThan(0)

    // Consensus bar: 18/(18+3) = 85.7% → Math.round → 86%
    // ConsensusBar renders the number as one span and "Konsens" as another.
    expect(screen.getByText('Konsens')).toBeInTheDocument()

    // Up / Down counts visible
    expect(screen.getByText('18')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()

    // View count
    expect(screen.getByText('123 Aufrufe')).toBeInTheDocument()
  })

  it('shows a singular view label for exactly one view', async () => {
    mockFetch(makeIdeaDetailResponse({ view_count: 1 }))

    renderIdeaDetailPage()

    await waitFor(() => expect(screen.getByText('1 Aufruf')).toBeInTheDocument())
  })

  it('renders not-found state when API returns 404', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Idee nicht gefunden.' } }, 404)

    renderIdeaDetailPage('demo', '99999')

    await waitFor(() => expect(screen.getByText('Idee nicht gefunden')).toBeInTheDocument())

    // A helpful description should be present
    expect(
      screen.getByText(/gehört zu einem anderen Board oder wurde zurückgezogen/),
    ).toBeInTheDocument()

    // Back-link to board
    expect(screen.getByRole('link', { name: /Zurück zum Board/i })).toBeInTheDocument()
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
})
