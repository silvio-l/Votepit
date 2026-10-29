/**
 * RTL tests for RoadmapPage — Spalten-View (Issue 04).
 *
 * Verifies: toggle behaviour, roadmap card content, empty-column state,
 * responsive grid class assertion.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { _resetTranslationCachesForTests } from '../hooks/useIdeaTranslation'
import { setVoterPreview } from '../lib/voterPreview'
import RoadmapPage from '../pages/RoadmapPage'
import { triggerIntersection } from '../test-setup'

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
const AUTHED_TRANSLATABLE_BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  translation_available: true,
  user: {
    id: 1,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo', role: 'member' }],
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
    start_date: string | null
    target_date: string | null
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
    start_date: overrides.start_date ?? null,
    target_date: overrides.target_date ?? null,
  }
}

function makeRoadmapResponse(
  groupOverrides: {
    planned?: ReturnType<typeof makeRoadmapIdea>[]
    in_progress?: ReturnType<typeof makeRoadmapIdea>[]
    done?: ReturnType<typeof makeRoadmapIdea>[]
    translation_enabled?: boolean
    roadmap_gantt_allowed?: boolean
  } = {},
) {
  return {
    board: {
      id: 1,
      slug: 'demo',
      name: 'Demo Board',
      intro: 'Willkommen!',
      translation_enabled: groupOverrides.translation_enabled ?? false,
    },
    groups: {
      planned: groupOverrides.planned ?? [],
      in_progress: groupOverrides.in_progress ?? [],
      done: groupOverrides.done ?? [],
    },
    roadmap_gantt_allowed: groupOverrides.roadmap_gantt_allowed ?? false,
  }
}

/** Mirrors BoardPage.test.tsx's mockFetchWithTranslation for RoadmapPage's bootstrap→roadmap→translate call order. */
function mockFetchWithTranslation(
  roadmapResponse: object,
  translateResponses: Record<number, { body: object; status?: number }> = {},
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
    if (url.includes('/bootstrap')) {
      return new Response(JSON.stringify(AUTHED_TRANSLATABLE_BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(JSON.stringify(roadmapResponse), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return spy
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

  it('toggle "Spalten" shows the idea card with title, net and approval, votes read-only', async () => {
    const user = userEvent.setup()
    mockFetch(
      makeRoadmapResponse({
        planned: [
          makeRoadmapIdea({
            title: 'Kanban-Feature',
            score_cache: 99,
            up_count: 99,
            down_count: 0,
          }),
        ],
      }),
    )
    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Spalten' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Spalten' }))

    await waitFor(() => {
      expect(screen.getByText('Kanban-Feature')).toBeInTheDocument()
      expect(screen.getByText('+99')).toBeInTheDocument()
      expect(screen.getByText('Zustimmung')).toBeInTheDocument()
    })
    // Read-only: counts stay visible, vote controls are aria-disabled and explain why.
    const voteButtons = screen.getAllByRole('button', { name: /^(Dafür|Dagegen) stimmen/ })
    expect(voteButtons.length).toBeGreaterThan(0)
    for (const button of voteButtons) expect(button).toHaveAttribute('aria-disabled', 'true')
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

    await waitFor(() => expect(screen.getByTestId('not-found-title')).toBeInTheDocument())
    expect(screen.getByTestId('not-found-home')).toHaveTextContent('Zur Startseite')
    expect(screen.queryByText('Board nicht gefunden')).not.toBeInTheDocument()
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
    expect(screen.queryByTestId('not-found-title')).not.toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: /Erneut versuchen/i }))

    await waitFor(() => expect(screen.getByText('Test Idee')).toBeInTheDocument())
  })
})

describe('RoadmapPage — voter-preview propagation', () => {
  it('landing with ?view=voter hides the Settings link for an owner', async () => {
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPageAt('/demo/roadmap?view=voter')

    await waitFor(() => expect(screen.getByTestId('board-profile-tab-roadmap')).toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voter-Vorschau beenden' })).toBeInTheDocument()
  })

  it('picks up preview mode from the module store — sticky navigation from another board page', async () => {
    setVoterPreview(true)
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByTestId('board-profile-tab-roadmap')).toBeInTheDocument())
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
  })

  it('owner without the preview toggle still sees the Settings link', async () => {
    makeFetchMock([{ body: OWNER_BOOTSTRAP_RESPONSE }, { body: makeRoadmapResponse() }])

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByTestId('board-profile-tab-roadmap')).toBeInTheDocument())
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()
  })

  it('an owner sees the Gantt edit button by default', async () => {
    makeFetchMock([
      { body: OWNER_BOOTSTRAP_RESPONSE },
      {
        body: makeRoadmapResponse({
          roadmap_gantt_allowed: true,
          planned: [makeRoadmapIdea({ start_date: '2026-01-01', target_date: '2026-02-01' })],
        }),
      },
    ])

    renderRoadmapPage()

    // 'Test Idee' also renders in the initial List view (the default) before
    // the roadmap_gantt_allowed effect switches to Gantt, so the edit button
    // itself — Gantt-only — is the thing to wait for, not the shared title.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Bearbeiten' })).toBeInTheDocument(),
    )
  })

  it('hides the Gantt edit button for an owner in voter preview (regression)', async () => {
    makeFetchMock([
      { body: OWNER_BOOTSTRAP_RESPONSE },
      {
        body: makeRoadmapResponse({
          roadmap_gantt_allowed: true,
          planned: [makeRoadmapIdea({ start_date: '2026-01-01', target_date: '2026-02-01' })],
        }),
      },
    ])

    renderRoadmapPageAt('/demo/roadmap?view=voter')

    await waitFor(() => expect(screen.getByText('Test Idee')).toBeInTheDocument())
    expect(screen.queryByRole('button', { name: 'Bearbeiten' })).not.toBeInTheDocument()
  })
})

describe('RoadmapPage — auto-translate (viewport-lazy, consistent with BoardPage)', () => {
  beforeEach(() => {
    _resetTranslationCachesForTests()
  })

  it('never calls the translate endpoint when the board has not opted in, even for an authenticated viewer', async () => {
    const spy = mockFetchWithTranslation(
      makeRoadmapResponse({
        planned: [makeRoadmapIdea({ id: 5, title: 'Fremdsprachige Idee' })],
        translation_enabled: false,
      }),
    )

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument())

    expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate'))).toBe(false)
  })

  it('does not call the translate endpoint for a list-view idea before it has scrolled into view', async () => {
    const spy = mockFetchWithTranslation(
      makeRoadmapResponse({
        planned: [makeRoadmapIdea({ id: 5, title: 'Fremdsprachige Idee' })],
        translation_enabled: true,
      }),
    )

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument())

    expect(spy.mock.calls.some((call) => String(call[0]).includes('/translate'))).toBe(false)
  })

  it('auto-translates a list-view idea once it scrolls into view, with its own show-original toggle', async () => {
    mockFetchWithTranslation(
      makeRoadmapResponse({
        planned: [
          makeRoadmapIdea({
            id: 5,
            title: 'Fremdsprachige Idee',
            body: 'Fremdsprachige Beschreibung.',
          }),
        ],
        translation_enabled: true,
      }),
      {
        5: {
          body: {
            translated_title: 'Translated idea',
            translated_body: 'Translated description.',
            detected_source_locale: 'en',
          },
        },
      },
    )

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument())

    const listItem = screen.getByRole('listitem')
    triggerIntersection(listItem.firstElementChild as Element)

    await waitFor(() => expect(screen.getByText('Translated idea')).toBeInTheDocument())
    expect(screen.getByText('Maschinell übersetzt')).toBeInTheDocument()

    await userEvent.setup().click(screen.getByRole('button', { name: 'Original anzeigen' }))

    expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument()
  })

  it('translates the title in columns view too', async () => {
    const user = userEvent.setup()
    mockFetchWithTranslation(
      makeRoadmapResponse({
        planned: [makeRoadmapIdea({ id: 5, title: 'Fremdsprachige Idee' })],
        translation_enabled: true,
      }),
      {
        5: {
          body: {
            translated_title: 'Translated idea',
            translated_body: 'Translated description.',
            detected_source_locale: 'en',
          },
        },
      },
    )

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Spalten' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Spalten' }))

    await waitFor(() => expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument())

    const listItem = screen.getByRole('listitem')
    triggerIntersection(listItem.firstElementChild as Element)

    await waitFor(() => expect(screen.getByText('Translated idea')).toBeInTheDocument())
  })

  it('translates the title in Gantt view too (regression)', async () => {
    const user = userEvent.setup()
    mockFetchWithTranslation(
      makeRoadmapResponse({
        planned: [
          makeRoadmapIdea({
            id: 5,
            title: 'Fremdsprachige Idee',
            start_date: '2026-01-01',
            target_date: '2026-02-01',
          }),
        ],
        translation_enabled: true,
        roadmap_gantt_allowed: true,
      }),
      {
        5: {
          body: {
            translated_title: 'Translated idea',
            translated_body: 'Translated description.',
            detected_source_locale: 'en',
          },
        },
      },
    )

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByRole('tab', { name: 'Gantt' })).toBeInTheDocument())
    await user.click(screen.getByRole('tab', { name: 'Gantt' }))

    await waitFor(() => expect(screen.getByRole('listitem')).toBeInTheDocument())
    expect(screen.getByText('Fremdsprachige Idee')).toBeInTheDocument()

    const listItem = screen.getByRole('listitem')
    triggerIntersection(listItem.firstElementChild as Element)

    await waitFor(() => expect(screen.getByText('Translated idea')).toBeInTheDocument())
  })
})

describe('RoadmapPage — board branding (same look as the board page)', () => {
  it('opens with the board profile header and carries the accent, the cover band and the branded footer', async () => {
    const response = makeRoadmapResponse()
    response.board = {
      ...response.board,
      primary_color: '#1fa890',
      secondary_color: null,
      logo_url: null,
      logo_public_id: null,
      logo_allowed: false,
      cover_pattern: 'waves',
      assets: {},
      show_badge: true,
      back_link: null,
      footer_note: 'Sponsored by us',
    } as typeof response.board
    mockFetch(response)

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByTestId('roadmap-profile')).toBeInTheDocument())
    expect(screen.getByTestId('board-cover')).toBeInTheDocument()
    expect(screen.getByTestId('board-footer-note')).toHaveTextContent('Sponsored by us')
    // Community edition (the default in this suite): the badge links to the public project.
    expect(screen.getByTestId('board-powered-by-link')).toHaveAttribute(
      'href',
      'https://github.com/silvio-l/Votepit',
    )
    expect(document.querySelector('[style*="--color-vp-accent"]')).not.toBeNull()
  })

  it('hides the Powered by badge when the plan hides it server-side', async () => {
    const response = makeRoadmapResponse()
    response.board = {
      ...response.board,
      primary_color: null,
      show_badge: false,
    } as typeof response.board
    mockFetch(response)

    renderRoadmapPage()

    await waitFor(() => expect(screen.getByTestId('roadmap-profile')).toBeInTheDocument())
    expect(screen.queryByTestId('board-powered-by-link')).toBeNull()
  })
})
