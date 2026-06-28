/**
 * RTL tests for IdeaDetailPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * bootstrap() is also mocked to seed the CSRF token and anon session.
 */

import { render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import IdeaDetailPage from './IdeaDetailPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

function makeIdeaDetailResponse(
  overrides: {
    title?: string
    body?: string
    status?: string
    score_cache?: number
    up_count?: number
    down_count?: number
    comment_count?: number
    is_authenticated?: boolean
  } = {},
) {
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
      up_count: overrides.up_count ?? 9,
      down_count: overrides.down_count ?? 2,
      comment_count: overrides.comment_count ?? 3,
      created_at: '2025-06-01 10:00:00',
      updated_at: '2025-06-01 10:00:00',
    },
    is_authenticated: overrides.is_authenticated ?? false,
  }
}

/**
 * Mock fetch with two sequential responses:
 *   1. /api/bootstrap
 *   2. /{boardSlug}/ideas/{ideaId}
 */
function mockFetch(detailResponse: object, detailStatus = 200) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
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

function renderIdeaDetailPage(boardSlug = 'demo', ideaId = '42') {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/idea/${ideaId}`]}>
      <Routes>
        <Route path="/:boardSlug/idea/:ideaId" element={<IdeaDetailPage />} />
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
    expect(screen.getByText(/5 Kommentare/)).toBeInTheDocument()

    // Consensus bar: 18/(18+3) = 85.7% → Math.round → 86%
    // ConsensusBar renders the number as one span and "Konsens" as another.
    expect(screen.getByText('Konsens')).toBeInTheDocument()

    // Up / Down counts visible
    expect(screen.getByText('18')).toBeInTheDocument()
    expect(screen.getByText('3')).toBeInTheDocument()
  })

  it('renders not-found state when API returns 404', async () => {
    mockFetch({ error: { key: 'not_found', message: 'Idee nicht gefunden.' } }, 404)

    renderIdeaDetailPage('demo', '99999')

    await waitFor(() => expect(screen.getByText('Idee nicht gefunden')).toBeInTheDocument())

    // A helpful description should be present
    expect(
      screen.getByText(/existiert nicht oder gehört zu einem anderen Board/),
    ).toBeInTheDocument()

    // Back-link to board
    expect(screen.getByRole('link', { name: /Zurück zum Board/i })).toBeInTheDocument()
  })
})
