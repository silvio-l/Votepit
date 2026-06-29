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

const ADMIN_BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 1, is_admin: true } }
const USER_BOOTSTRAP = { csrf_token: 'test-csrf', user: { id: 2, is_admin: false } }
const ANON_BOOTSTRAP = { csrf_token: 'test-csrf', user: null }

function makeIdea(overrides: Partial<{ status: string; author_id: number }> = {}) {
  return {
    id: 42,
    board_id: 1,
    author_id: overrides.author_id ?? 99,
    title: 'Dunkelheit als Feature',
    body: 'Dark-Mode-Beschreibung.',
    status: overrides.status ?? 'open',
    score_cache: 5,
    up_count: 6,
    down_count: 1,
    comment_count: 0,
    created_at: '2025-06-01 10:00:00',
    updated_at: '2025-06-01 10:00:00',
    my_vote: 'none',
  }
}

function makeDetailResponse(idea: ReturnType<typeof makeIdea>, isAuthenticated = true) {
  return {
    board: { id: 1, slug: 'demo', name: 'Demo Board' },
    idea,
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
  })
})
