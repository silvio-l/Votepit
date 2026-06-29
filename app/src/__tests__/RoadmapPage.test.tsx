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
import RoadmapPage from '../pages/RoadmapPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

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

function renderRoadmapPage(slug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/${slug}/roadmap`]}>
      <Routes>
        <Route path="/:boardSlug/roadmap" element={<RoadmapPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
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

    // Three empty columns → at least one "Keine Ideen" empty state visible
    await waitFor(() => expect(screen.getAllByText('Keine Ideen').length).toBeGreaterThanOrEqual(1))
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
