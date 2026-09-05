/**
 * RTL tests for RoadmapPage — Spalten-View (Issue 04).
 *
 * Verifies: toggle behaviour, RoadmapCard content, empty-column state,
 * responsive grid class assertion.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setVoterPreview } from '../lib/voterPreview'
import RoadmapPage from '../pages/RoadmapPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }
const OWNER_BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo', role: 'owner' }],
  },
}

function makeRoadmapIdea(
  overrides: Partial<{
    id: number
    title: string
    body: string
    status: string
    score_cache: number
    up_count: number
    down_count: number
    comment_count: number
  }> = {},
) {
  return {
    id: overrides.id ?? 1,
    title: overrides.title ?? 'Test Idee',
    body: overrides.body ?? 'Beschreibung',
    status: overrides.status ?? 'planned',
    score_cache: overrides.score_cache ?? 42,
    up_count: overrides.up_count ?? 50,
    down_count: overrides.down_count ?? 8,
    comment_count: overrides.comment_count ?? 3,
    created_at: '2025-06-01 10:00:00',
  }
}

function makeRoadmapResponse(
  groupOverrides: {
    planned?: ReturnType<typeof makeRoadmapIdea>[]
    in_progress?: ReturnType<typeof makeRoadmapIdea>[]
    done?: ReturnType<typeof makeRoadmapIdea>[]
  } = {},
) {
  return {
    board: { id: 1, slug: 'demo', name: 'Demo Board', intro: 'Willkommen!' },
    groups: {
      planned: groupOverrides.planned ?? [],
      in_progress: groupOverrides.in_progress ?? [],
      done: groupOverrides.done ?? [],
    },
  }
}

function mockFetch(roadmapResponse: object) {
  const responses = [JSON.stringify(BOOTSTRAP_RESPONSE), JSON.stringify(roadmapResponse)]
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

function renderRoadmapPage(slug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/${slug}/roadmap`]}>
      <Routes>
        <Route path="/:boardSlug/roadmap" element={<RoadmapPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderRoadmapPageAt(entry: string) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/:boardSlug/roadmap" element={<RoadmapPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  setVoterPreview(false)
})

describe('RoadmapPage — Spalten-View (Issue 04)', () => {
  it('"Liste" is the default active view', async () => {
    mockFetch(makeRoadmapResponse())
    renderRoadmapPage()

    await waitFor(() =>
      expect(screen.getByRole('tab', { name: 'Liste' })).toHaveAttribute('aria-selected', 'true'),
    )
    expect(screen.getByRole('tab', { name: 'Spalten' })).toHaveAttribute('aria-selected', 'false')
  })

  it('toggle "Spalten" shows RoadmapCard with title, score and "Stimmen" — no vote buttons', async () => {
    const user = userEvent.setup()
    mockFetch(
      makeRoadmapResponse({
        planned: [makeRoadmapIdea({ title: 'Kanban-Feature', score_cache: 99 })],
      }),
    )
    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Spalten' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Spalten' }))

    // Wait for the columns view to fully render (RoadmapCard shows "Stimmen" without slash prefix;
    // RoadmapRow in the list view shows "/ Stimmen" so "Stimmen" alone = columns view)
    await waitFor(() => {
      expect(screen.getByText('Kanban-Feature')).toBeInTheDocument()
      expect(screen.getByText('Stimmen')).toBeInTheDocument()
    })
    expect(screen.getByText('99')).toBeInTheDocument()

    // No VoteWidget
    expect(screen.queryByRole('button', { name: /upvote/i })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /downvote/i })).not.toBeInTheDocument()
  })

  it('empty column shows EmptyState', async () => {
    const user = userEvent.setup()
    mockFetch(makeRoadmapResponse()) // all groups empty
    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Spalten' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Spalten' }))

    // Three empty columns → each states what is missing in that column
    await waitFor(() => expect(screen.getByText('Noch nichts geplant')).toBeInTheDocument())
    expect(screen.getByText('Gerade nichts in Arbeit')).toBeInTheDocument()
    expect(screen.getByText('Noch nichts erledigt')).toBeInTheDocument()
  })

  it('columns view uses responsive grid — grid-cols-1 (mobile) + md:grid-cols-3 (desktop)', async () => {
    const user = userEvent.setup()
    mockFetch(makeRoadmapResponse())
    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Spalten' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Spalten' }))

    await waitFor(() => {
      const grid = document.querySelector('.grid')
      expect(grid).not.toBeNull()
      expect(grid?.className).toContain('grid-cols-1')
      expect(grid?.className).toContain('md:grid-cols-3')
    })
  })
})

describe('RoadmapPage — not found vs. transient error', () => {
  it('shows a dead-end-free not-found state with a link back home for a 404', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_RESPONSE },
      { body: { error: { key: 'not_found', message: 'Board not found' } }, status: 404 },
    ])

    renderRoadmapPage('unknown-board')

    await waitFor(() => expect(screen.getByText('Board nicht gefunden')).toBeInTheDocument())
    expect(screen.getByRole('button', { name: /Zur Startseite/i })).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
  })

  it('shows a distinct, retryable error state for a transient failure, separate from empty state', async () => {
    const user = userEvent.setup()
    makeFetchMock([
      { body: BOOTSTRAP_RESPONSE },
      { body: { error: { key: 'server_error', message: 'Server error' } }, status: 500 },
      { body: makeRoadmapResponse({ planned: [makeRoadmapIdea()] }) },
    ])

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByText('Fehler beim Laden')).toBeInTheDocument()
    expect(screen.queryByText('Board nicht gefunden')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Erneut versuchen/i }))

    await waitFor(() => expect(screen.getByText('Test Idee')).toBeInTheDocument())
  })
})

describe('RoadmapPage — voter-preview propagation', () => {
  it('landing with ?view=voter hides the Settings link for an owner', async () => {
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPageAt('/demo/roadmap?view=voter')

    await waitFor(() => expect(screen.getByText('Roadmap')).toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voter-Vorschau beenden' })).toBeInTheDocument()
  })

  it('picks up preview mode from the module store — sticky navigation from another board page', async () => {
    setVoterPreview(true)
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByText('Roadmap')).toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
  })

  it('owner without the preview toggle still sees the Settings link', async () => {
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByText('Roadmap')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()
  })
})
