/**
 * RTL tests for BoardPage's owner-only momentum-milestone celebrations
 * (first idea ever, first idea from someone other than the owner, 10 ideas
 * total) — see confettiFx.ts/celebrations.ts. Scoped to just this behaviour,
 * not full BoardPage coverage (no pre-existing BoardPage test file to extend).
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import BoardPage from './BoardPage'

const BOOTSTRAP_OWNER = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: true },
}

const BOOTSTRAP_VOTER = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false },
}

const BASE_BOARD = {
  board: { id: 1, slug: 'acme', name: 'Acme Board', intro: '', show_badge: true },
  active_status: null,
  active_sort: 'top',
  page: 1,
  total_pages: 1,
  is_authenticated: true,
}

function boardResponse(overrides: { ideas?: Array<{ author_id: number }>; total_ideas?: number }) {
  const ideas = (overrides.ideas ?? []).map((idea, i) => ({
    id: i + 1,
    board_id: 1,
    author_id: idea.author_id,
    title: `Idea ${i + 1}`,
    body: 'Body',
    status: 'open',
    is_pinned: false,
    score_cache: 0,
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    comment_count: 0,
    up_count: 0,
    down_count: 0,
  }))
  return {
    ...BASE_BOARD,
    ideas,
    stats: {
      weekly_votes: 0,
      weekly_new_ideas: 0,
      avg_consensus: 0,
      total_ideas: overrides.total_ideas ?? ideas.length,
    },
  }
}

function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    if (typeof input === 'string' && input.startsWith('/notifications')) {
      return new Response(JSON.stringify({ notifications: [] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    const r = responses[callIndex] ?? responses[responses.length - 1]
    callIndex++
    return new Response(JSON.stringify(r.body), {
      status: r.status ?? 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
}

function renderBoardPage() {
  return render(
    <MemoryRouter initialEntries={['/acme']}>
      <Routes>
        <Route path="/:boardSlug" element={<BoardPage />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
})

describe('BoardPage — owner milestones', () => {
  it('does not celebrate on a plain first visit (nothing was seen before)', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OWNER }, { body: boardResponse({ total_ideas: 12 }) }])

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Acme Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByText(/Die erste Idee ist da!/)).not.toBeInTheDocument()
    expect(screen.queryByText(/10 Ideen erreicht!/)).not.toBeInTheDocument()
  })

  it('celebrates the first idea once this browser has seen the board with zero ideas before', async () => {
    localStorage.setItem('vp_celebration_count:board:acme:total-ideas:first', '0')
    makeFetchMock([{ body: BOOTSTRAP_OWNER }, { body: boardResponse({ total_ideas: 1 }) }])

    renderBoardPage()

    await waitFor(() => expect(screen.getByText(/Die erste Idee ist da!/)).toBeInTheDocument())
  })

  it('celebrates crossing 10 ideas once this browser has seen fewer before', async () => {
    localStorage.setItem('vp_celebration_count:board:acme:total-ideas:first', '5')
    localStorage.setItem('vp_celebration_count:board:acme:total-ideas:ten', '9')
    makeFetchMock([{ body: BOOTSTRAP_OWNER }, { body: boardResponse({ total_ideas: 10 }) }])

    renderBoardPage()

    await waitFor(() => expect(screen.getByText(/10 Ideen erreicht!/)).toBeInTheDocument())
  })

  it('celebrates the first idea from someone other than the owner', async () => {
    localStorage.setItem('vp_celebration_count:board:acme:total-ideas:first', '3')
    localStorage.setItem('vp_celebration_count:board:acme:total-ideas:ten', '3')
    makeFetchMock([
      { body: BOOTSTRAP_OWNER },
      { body: boardResponse({ ideas: [{ author_id: 1 }, { author_id: 42 }], total_ideas: 3 }) },
    ])

    renderBoardPage()

    await waitFor(() => expect(screen.getByText(/Die erste Fremdidee!/)).toBeInTheDocument())
  })

  it('never celebrates for a non-owner viewer', async () => {
    makeFetchMock([{ body: BOOTSTRAP_VOTER }, { body: boardResponse({ total_ideas: 1 }) }])

    renderBoardPage()

    await waitFor(() =>
      expect(screen.getByRole('heading', { name: 'Acme Board' })).toBeInTheDocument(),
    )
    expect(screen.queryByText(/Die erste Idee ist da!/)).not.toBeInTheDocument()
  })
})
