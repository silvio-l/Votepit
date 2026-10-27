/**
 * RTL tests for the optional submission/voting windows on the board page
 * (broaden-use-cases issue 04): the phase line for upcoming/open/closed,
 * the locked vote buttons + "Idee teilen" CTA with their reason, and the
 * owner/admin exemption for submissions. Gating follows the SERVER state
 * only — the browser clock is deliberately set to contradict it.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { BoardPhasesInfo } from '../lib/api'
import BoardPage from './BoardPage'

const MEMBER = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false, memberships: [] },
}

const OWNER = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: false,
    memberships: [{ account_id: 1, account_slug: 'default', account_name: 'A', role: 'owner' }],
  },
}

const OPEN_WINDOW = { state: 'open' as const, opens_at: null, closes_at: null }

function boardResponse(phases?: BoardPhasesInfo) {
  return {
    board: { id: 1, slug: 'acme', name: 'Acme Board', intro: '', show_badge: true, phases },
    active_status: null,
    active_sort: 'new',
    page: 1,
    total_pages: 1,
    is_authenticated: true,
    ideas: [1, 2].map((id) => ({
      id,
      board_id: 1,
      author_id: 9,
      title: `Idea ${id}`,
      body: 'Body',
      status: 'open',
      is_pinned: false,
      score_cache: 0,
      view_count: 0,
      created_at: '2026-01-01 00:00:00',
      updated_at: '2026-01-01 00:00:00',
      comment_count: 0,
      up_count: 0,
      down_count: 0,
      tags: [],
    })),
    stats: { weekly_votes: 0, weekly_new_ideas: 0, avg_consensus: 0, total_ideas: 2 },
  }
}

function mockFetch(bootstrap: object, board: object) {
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url
    const body = url.startsWith('/notifications')
      ? { notifications: [] }
      : url.includes('bootstrap')
        ? bootstrap
        : board
    return new Response(JSON.stringify(body), {
      status: 200,
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

async function loaded() {
  await waitFor(() =>
    expect(screen.getByRole('heading', { name: 'Acme Board' })).toBeInTheDocument(),
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
  localStorage.clear()
  // A browser clock that contradicts every server state below: if the page
  // gated on Date.now(), these tests would see the opposite result.
  vi.spyOn(Date, 'now').mockReturnValue(new Date('2031-01-01T00:00:00Z').getTime())
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('BoardPage — deadlines', () => {
  it('shows no phase line and keeps everything enabled on a default board', async () => {
    mockFetch(MEMBER, boardResponse())
    renderBoardPage()
    await loaded()

    expect(screen.queryByTestId('board-phase-line')).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Idee teilen/ })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /^Dafür stimmen/ }).length).toBeGreaterThan(0)
  })

  it('renders "upcoming" and locks voting with the reason', async () => {
    mockFetch(
      MEMBER,
      boardResponse({
        submissions: OPEN_WINDOW,
        voting: { state: 'upcoming', opens_at: '2026-05-01T08:00:00Z', closes_at: null },
      }),
    )
    renderBoardPage()
    await loaded()

    expect(screen.getByTestId('board-phase-line')).toHaveTextContent(/Abstimmung öffnet am/)
    // Locked votes stay visible with aria-disabled; the reason is the accessible description.
    const locked = screen.getAllByRole('button', { name: /^Dafür stimmen/ })
    expect(locked.length).toBeGreaterThan(0)
    for (const button of locked) {
      expect(button).toHaveAttribute('aria-disabled', 'true')
      expect(button).toHaveAccessibleDescription(/noch nicht geöffnet/)
    }
  })

  it('renders "open until" without locking anything', async () => {
    mockFetch(
      MEMBER,
      boardResponse({
        submissions: { state: 'open', opens_at: null, closes_at: '2026-05-31T22:00:00Z' },
        voting: { state: 'open', opens_at: null, closes_at: '2026-06-30T22:00:00Z' },
      }),
    )
    renderBoardPage()
    await loaded()

    const line = screen.getByTestId('board-phase-line')
    expect(line).toHaveTextContent(/Einreichungen offen bis/)
    expect(line).toHaveTextContent(/Abstimmung offen bis/)
    expect(screen.getByRole('link', { name: /Idee teilen/ })).toBeInTheDocument()
    expect(screen.getAllByRole('button', { name: /^Dafür stimmen/ })[0]).toBeEnabled()
  })

  it('renders "closed" and disables the submit CTA for a member', async () => {
    mockFetch(
      MEMBER,
      boardResponse({
        submissions: { state: 'closed', opens_at: null, closes_at: '2026-03-31T22:00:00Z' },
        voting: { state: 'closed', opens_at: null, closes_at: '2026-03-31T22:00:00Z' },
      }),
    )
    renderBoardPage()
    await loaded()

    const line = screen.getByTestId('board-phase-line')
    expect(line).toHaveTextContent(/Einreichungen geschlossen seit/)
    expect(line).toHaveTextContent(/Abstimmung geschlossen seit/)
    expect(screen.queryByRole('link', { name: /Idee teilen/ })).not.toBeInTheDocument()
    const cta = screen.getByRole('button', { name: /Neue Idee/ })
    expect(cta).toBeDisabled()
    expect(cta).toHaveAttribute('title', 'Einreichungen sind geschlossen')
    expect(screen.getAllByRole('button', { name: /^Dafür stimmen/ })[0]).toHaveAttribute(
      'aria-disabled',
      'true',
    )
  })

  it('keeps the submit CTA for the owner while submissions are closed', async () => {
    mockFetch(
      OWNER,
      boardResponse({
        submissions: { state: 'closed', opens_at: null, closes_at: '2026-03-31T22:00:00Z' },
        voting: OPEN_WINDOW,
      }),
    )
    renderBoardPage()
    await loaded()

    expect(screen.getByRole('link', { name: /Idee teilen/ })).toBeInTheDocument()
    expect(screen.getByText(/als Admin kannst du trotzdem Ideen anlegen/)).toBeInTheDocument()
  })
})
