/**
 * RTL tests for IdeaDetailPage — user-visible behaviour only (Issue 02).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  AC1: Admin sees status control with valid next-status options; changing status
 *       calls the status endpoint and the badge updates.
 *  AC2: Non-admin (and unauthenticated user) sees only the read-only StatusBadge,
 *       no status control.
 *  AC3: Transitions not listed in StatusService are never rendered as options;
 *       a server 422 error is surfaced as an inline message.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IdeaDetailPage from '../pages/IdeaDetailPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

// Self-host: no setAccountSlug() call in these tests, so accountRoleFor
// falls back to the sole membership (self-host is always one account).
const ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: true,
    is_operator: false,
    memberships: [{ account_slug: 'self', role: 'owner' }],
  },
}
const USER_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false, is_operator: false, memberships: [] },
}
const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

function makeIdea(
  overrides: Partial<{ status: string; author_id: number; is_pinned: boolean }> = {},
) {
  return {
    id: 42,
    board_id: 1,
    author_id: overrides.author_id ?? 99,
    title: 'Dunkelheit als Feature',
    body: 'Dark-Mode-Beschreibung.',
    status: overrides.status ?? 'open',
    is_pinned: overrides.is_pinned ?? false,
    score_cache: 5,
    up_count: 6,
    down_count: 1,
    comment_count: 0,
    created_at: '2025-06-01 10:00:00',
    updated_at: '2025-06-01 10:00:00',
    my_vote: 'none',
  }
}

function makeDetailResponse(
  idea: ReturnType<typeof makeIdea>,
  isAuthenticated = true,
  comments: Array<{
    id: number
    idea_id: number
    author_id: number
    body: string
    created_at: string
  }> = [],
) {
  return {
    board: { id: 1, slug: 'demo', name: 'Demo Board' },
    idea,
    comments,
    is_authenticated: isAuthenticated,
  }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build a sequential fetch mock from a list of response payloads. */
function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderDetailPage(boardSlug = 'demo', ideaId = '42') {
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

describe('IdeaDetailPage — admin status control (AC1)', () => {
  it('admin sees a "Status ändern" select with valid next options for "open"', async () => {
    const idea = makeIdea({ status: 'open' })
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    // Status control is present
    const select = screen.getByRole('combobox', { name: 'Status ändern' })
    expect(select).toBeInTheDocument()

    // Valid transitions from "open": planned, in_progress, done, declined
    // (Status labels are in German)
    expect(screen.getByRole('option', { name: 'Geplant' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'In Arbeit' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Erledigt' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Abgelehnt' })).toBeInTheDocument()
    // "Offen" (self) is NOT a valid transition from open
    expect(screen.queryByRole('option', { name: 'Offen' })).not.toBeInTheDocument()
  })

  it('admin changes status: API POST called, badge updates to new status', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ status: 'open' })

    // Capture fetch calls for inspection
    const fetchCalls: Array<{ url: string; body?: string }> = []

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const body = typeof init?.body === 'string' ? init.body : undefined
      fetchCalls.push({ url, body })

      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/status')) {
        return new Response(JSON.stringify({ ok: true, status: 'planned' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      // default: idea detail
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    // Select "Geplant"
    const select = screen.getByRole('combobox', { name: 'Status ändern' })
    await user.selectOptions(select, 'planned')

    // Badge updates optimistically to "Geplant"
    await waitFor(() => expect(screen.getByText('Geplant')).toBeInTheDocument())

    // API was called with correct body
    const statusCall = fetchCalls.find((c) => c.url.includes('/status'))
    expect(statusCall).toBeDefined()
    expect(statusCall?.body).toContain('"status":"planned"')
  })

  it('admin status select shows only valid transitions from "in_progress"', async () => {
    const idea = makeIdea({ status: 'in_progress' })
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    // Valid transitions from in_progress: done, declined, planned
    expect(screen.getByRole('option', { name: 'Erledigt' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Abgelehnt' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Geplant' })).toBeInTheDocument()
    // "In Arbeit" (self) and "Offen" are NOT valid
    expect(screen.queryByRole('option', { name: 'In Arbeit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Offen' })).not.toBeInTheDocument()
  })

  it('server 422 shows an error message and reverts the badge', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ status: 'open' })

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/status')) {
        return new Response(
          JSON.stringify({ error: { key: 'invalid_transition', message: 'Ungültiger Übergang.' } }),
          { status: 422, headers: { 'Content-Type': 'application/json' } },
        )
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const select = screen.getByRole('combobox', { name: 'Status ändern' })
    await user.selectOptions(select, 'planned')

    // Error message shown
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Ungültiger Übergang.'))
    // Badge reverts to original status
    expect(screen.getByText('Offen')).toBeInTheDocument()
  })
})

describe('IdeaDetailPage — admin pin control', () => {
  it('admin sees an "Anpinnen" button for an unpinned idea', async () => {
    const idea = makeIdea({ is_pinned: false })
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const pinButton = screen.getByRole('button', { name: 'Anpinnen' })
    expect(pinButton).toBeInTheDocument()
    expect(pinButton).toHaveAttribute('aria-pressed', 'false')
  })

  it('admin sees an "Angepinnt" button for an already-pinned idea', async () => {
    const idea = makeIdea({ is_pinned: true })
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const pinButton = screen.getByRole('button', { name: 'Angepinnt' })
    expect(pinButton).toBeInTheDocument()
    expect(pinButton).toHaveAttribute('aria-pressed', 'true')
  })

  it('admin pins an idea: API POST called with pinned:true, button label updates', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ is_pinned: false })

    const fetchCalls: Array<{ url: string; body?: string }> = []

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const body = typeof init?.body === 'string' ? init.body : undefined
      fetchCalls.push({ url, body })

      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/pin')) {
        return new Response(JSON.stringify({ ok: true, pinned: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const pinButton = screen.getByRole('button', { name: 'Anpinnen' })
    await user.click(pinButton)

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Angepinnt' })).toBeInTheDocument(),
    )

    const pinCall = fetchCalls.find((c) => c.url.includes('/pin'))
    expect(pinCall).toBeDefined()
    expect(pinCall?.body).toContain('"pinned":true')
  })

  it('server error reverts the pin button and shows an inline message', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ is_pinned: false })

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/pin')) {
        return new Response(
          JSON.stringify({ error: { key: 'not_found', message: 'Idee nicht gefunden.' } }),
          { status: 404, headers: { 'Content-Type': 'application/json' } },
        )
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const pinButton = screen.getByRole('button', { name: 'Anpinnen' })
    await user.click(pinButton)

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Idee nicht gefunden.'))
    expect(screen.getByRole('button', { name: 'Anpinnen' })).toBeInTheDocument()
  })
})

describe('IdeaDetailPage — admin block control', () => {
  it('admin sees a "Autor sperren (Account)" button for an idea author', async () => {
    const idea = makeIdea()
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const blockButton = screen.getByRole('button', { name: 'Autor sperren (Account)' })
    expect(blockButton).toBeInTheDocument()
    expect(blockButton).toHaveAttribute('aria-pressed', 'false')
  })

  it('admin blocks the author: API POST called with user_id + blocked:true, button label updates', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ author_id: 99 })

    const fetchCalls: Array<{ url: string; body?: string }> = []

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const body = typeof init?.body === 'string' ? init.body : undefined
      fetchCalls.push({ url, body })

      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/block')) {
        return new Response(JSON.stringify({ ok: true, blocked: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const blockButton = screen.getByRole('button', { name: 'Autor sperren (Account)' })
    await user.click(blockButton)

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Autor gesperrt (Account)' })).toBeInTheDocument(),
    )

    const blockCall = fetchCalls.find((c) => c.url.includes('/block'))
    expect(blockCall).toBeDefined()
    expect(blockCall?.body).toContain('"user_id":99')
    expect(blockCall?.body).toContain('"blocked":true')
  })

  it('server error reverts the block button and shows an inline message', async () => {
    const user = userEvent.setup()
    const idea = makeIdea()

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/block')) {
        return new Response(
          JSON.stringify({ error: { key: 'user_not_found', message: 'Nutzer nicht gefunden.' } }),
          { status: 404, headers: { 'Content-Type': 'application/json' } },
        )
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const blockButton = screen.getByRole('button', { name: 'Autor sperren (Account)' })
    await user.click(blockButton)

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent('Nutzer nicht gefunden.'),
    )
    expect(screen.getByRole('button', { name: 'Autor sperren (Account)' })).toBeInTheDocument()
  })

  it('admin picks board scope: API POST called with scope:board, button label reflects the board scope', async () => {
    const user = userEvent.setup()
    const idea = makeIdea({ author_id: 99 })

    const fetchCalls: Array<{ url: string; body?: string }> = []

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const body = typeof init?.body === 'string' ? init.body : undefined
      fetchCalls.push({ url, body })

      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/block')) {
        return new Response(JSON.stringify({ ok: true, blocked: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(makeDetailResponse(idea)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    const scopeSelect = screen.getByRole('combobox', { name: 'Sperr-Umfang' })
    await user.selectOptions(scopeSelect, 'board')

    expect(screen.getByRole('button', { name: 'Autor sperren (Board)' })).toBeInTheDocument()

    const blockButton = screen.getByRole('button', { name: 'Autor sperren (Board)' })
    await user.click(blockButton)

    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Autor gesperrt (Board)' })).toBeInTheDocument(),
    )

    const blockCall = fetchCalls.find((c) => c.url.includes('/block'))
    expect(blockCall).toBeDefined()
    expect(blockCall?.body).toContain('"user_id":99')
    expect(blockCall?.body).toContain('"blocked":true')
    expect(blockCall?.body).toContain('"scope":"board"')
  })
})

describe('IdeaDetailPage — read-only badge for non-admin (AC2)', () => {
  it('logged-in non-admin user sees the badge but no status control', async () => {
    const idea = makeIdea({ status: 'planned' })
    makeFetchMock([{ body: USER_BOOTSTRAP }, { body: makeDetailResponse(idea) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    // Badge visible
    expect(screen.getByText('Geplant')).toBeInTheDocument()
    // No status control
    expect(screen.queryByRole('combobox', { name: 'Status ändern' })).not.toBeInTheDocument()
    // No pin control
    expect(screen.queryByRole('button', { name: 'Anpinnen' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Angepinnt' })).not.toBeInTheDocument()
  })

  it('anonymous user sees the badge but no status control', async () => {
    const idea = makeIdea({ status: 'done' })
    makeFetchMock([{ body: ANON_BOOTSTRAP }, { body: makeDetailResponse(idea, false) }])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Dunkelheit als Feature')).toBeInTheDocument())

    // Badge visible
    expect(screen.getByText('Erledigt')).toBeInTheDocument()
    // No status control
    expect(screen.queryByRole('combobox', { name: 'Status ändern' })).not.toBeInTheDocument()
    // No pin control
    expect(screen.queryByRole('button', { name: 'Anpinnen' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Angepinnt' })).not.toBeInTheDocument()
  })
})

describe('IdeaDetailPage — author display (profile-visibility)', () => {
  const seededComments = [
    { id: 1, idea_id: 42, author_id: 99, body: 'Ein Kommentar', created_at: '2025-06-01 10:00:00' },
    { id: 2, idea_id: 42, author_id: 99, body: 'Noch einer', created_at: '2025-06-01 11:00:00' },
    { id: 3, idea_id: 42, author_id: 2, body: 'Meiner', created_at: '2025-06-01 12:00:00' },
  ]

  /** URL-routed fetch mock: bootstrap, own profile, the idea, and one public profile. */
  function mockWithProfile(profile: object, profileStatus = 200) {
    const fetchCalls: string[] = []
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      fetchCalls.push(url)
      const json = (body: object, status = 200) =>
        new Response(JSON.stringify(body), {
          status,
          headers: { 'Content-Type': 'application/json' },
        })
      if (url.includes('/api/bootstrap')) return json(USER_BOOTSTRAP)
      if (url.includes('/account/profile')) return json({ avatar_url: '/avatar/me.jpg' })
      if (url.includes('/members/99/profile')) return json(profile, profileStatus)
      return json(makeDetailResponse(makeIdea({ author_id: 99 }), true, seededComments))
    })
    return fetchCalls
  }

  it('shows an anonymous author as "Voter" linked to their public profile page, and loads the profile only once', async () => {
    const fetchCalls = mockWithProfile({
      id: 99,
      visible: false,
      is_admin: false,
      is_operator: false,
      role: null,
    })

    renderDetailPage()
    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    // Idea author + two comments by user 99 → three "Voter" labels, all linked
    // to the public profile page (which itself renders the anonymous state).
    const links = await screen.findAllByRole('link', { name: 'Voter' })
    expect(links).toHaveLength(3)
    for (const link of links) {
      expect(link).toHaveAttribute('href', '/members/99/profile')
    }
    // The old ad-hoc label is gone for good.
    expect(screen.queryByText('Board-Mitglied')).not.toBeInTheDocument()
    // The current user's own comment says "Du", linked to their OWN public
    // profile page — so they can see exactly what others see.
    const ownLink = screen.getByRole('link', { name: 'Du' })
    expect(ownLink).toHaveAttribute('href', '/members/2/profile')

    // One request for author 99 despite three badges (page-level cache).
    expect(fetchCalls.filter((u) => u.includes('/members/99/profile'))).toHaveLength(1)
  })

  it('shows a visible author with their avatar and a link to the public profile', async () => {
    mockWithProfile({
      id: 99,
      visible: true,
      is_admin: false,
      is_operator: false,
      role: null,
      avatar_url: '/avatar/99.jpg',
      website_domain: null,
      x_handle: null,
      youtube_handle: null,
      github_username: null,
    })

    renderDetailPage()
    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    const links = await screen.findAllByRole('link', { name: 'Voter' })
    expect(links).toHaveLength(3)
    for (const link of links) {
      expect(link).toHaveAttribute('href', '/members/99/profile')
    }
    expect(document.querySelectorAll('img[src="/avatar/99.jpg"]')).toHaveLength(3)
  })

  it('shows the moderator badge for an anonymous author — the badge is independent of visibility', async () => {
    mockWithProfile({
      id: 99,
      visible: false,
      is_admin: true,
      is_operator: false,
      role: 'moderator',
    })

    renderDetailPage()
    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    await waitFor(() => expect(screen.getAllByText('Moderator')).toHaveLength(3))
    const links = await screen.findAllByRole('link', { name: 'Voter' })
    expect(links).toHaveLength(3)
    for (const link of links) {
      expect(link).toHaveAttribute('href', '/members/99/profile')
    }
  })

  it('still links to the public profile page when the profile lookup fails, falling back to the anonymous rendering', async () => {
    mockWithProfile({ error: { key: 'not_found', message: 'Nicht gefunden.' } }, 404)

    renderDetailPage()
    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    const links = await screen.findAllByRole('link', { name: 'Voter' })
    expect(links).toHaveLength(3)
    for (const link of links) {
      expect(link).toHaveAttribute('href', '/members/99/profile')
    }
  })
})

describe('IdeaDetailPage — admin comment moderation', () => {
  const seededComments = [
    { id: 1, idea_id: 42, author_id: 99, body: 'Ein Kommentar', created_at: '2025-06-01 10:00:00' },
  ]

  it('admin sees an "Entfernen" button next to a comment', async () => {
    const idea = makeIdea()
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: makeDetailResponse(idea, true, seededComments) },
    ])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: 'Entfernen' })).toBeInTheDocument()
  })

  it('non-admin does NOT see an "Entfernen" button next to a comment', async () => {
    const idea = makeIdea()
    makeFetchMock([
      { body: USER_BOOTSTRAP },
      { body: makeDetailResponse(idea, true, seededComments) },
    ])

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Entfernen' })).not.toBeInTheDocument()
  })

  it('admin removes a comment: API POST called, comment disappears from the list', async () => {
    const user = userEvent.setup()
    const idea = makeIdea()

    const fetchCalls: Array<{ url: string }> = []

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      fetchCalls.push({ url })

      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/comments/1/delete')) {
        return new Response(JSON.stringify({ ok: true }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      return new Response(JSON.stringify(makeDetailResponse(idea, true, seededComments)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    await user.click(screen.getByRole('button', { name: 'Entfernen' }))

    await waitFor(() => expect(screen.queryByText('Ein Kommentar')).not.toBeInTheDocument())
    expect(fetchCalls.some((c) => c.url.includes('/comments/1/delete'))).toBe(true)
  })

  it('server error reverts an optimistic comment removal and shows an inline message', async () => {
    const user = userEvent.setup()
    const idea = makeIdea()

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      if (url.includes('/api/bootstrap')) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (url.includes('/comments/1/delete')) {
        return new Response(
          JSON.stringify({ error: { key: 'not_found', message: 'Kommentar nicht gefunden.' } }),
          { status: 404, headers: { 'Content-Type': 'application/json' } },
        )
      }
      return new Response(JSON.stringify(makeDetailResponse(idea, true, seededComments)), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderDetailPage()

    await waitFor(() => expect(screen.getByText('Ein Kommentar')).toBeInTheDocument())

    await user.click(screen.getByRole('button', { name: 'Entfernen' }))

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent('Kommentar nicht gefunden.'),
    )
    // Rolled back: comment is visible again.
    expect(screen.getByText('Ein Kommentar')).toBeInTheDocument()
  })
})
