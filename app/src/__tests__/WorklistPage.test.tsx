/**
 * RTL tests for WorklistPage (P-06 slice 03, read only) and the worklist nav
 * entry with its badge in AdminShell.
 *
 * fetch is mocked per URL (not per call order), so the nav badge request
 * does not shift the page's responses. Test ids are the contract; German
 * strings come from the default (de) catalogue.
 */

import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import WorklistPage from '../pages/WorklistPage'

const OWNER_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    username: 'silvio',
    is_admin: false,
    is_operator: false,
    is_support: false,
    memberships: [{ account_slug: 'general', role: 'owner' }],
  },
}

const MEMBER_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: {
    id: 2,
    username: 'guest-member',
    is_admin: false,
    is_operator: false,
    is_support: false,
    memberships: [{ account_slug: 'general', role: 'member' }],
  },
}

const COUNTS = {
  unanswered: 4,
  unanswered_over_7_days: 1,
  pending_moderation: 2,
  decision_due: 1,
  // IDEA_A is unanswered and due at once, so it counts once in the total.
  total: 6,
}

const IDEA_A = {
  id: 11,
  title: 'Dark mode for the board',
  board_slug: 'general',
  board_name: 'General',
  status: 'open',
  status_category: 'open',
  status_is_system: true,
  status_label_de: null,
  status_label_en: null,
  moderation_state: 'published',
  up_count: 12,
  down_count: 1,
  voter_count: 13,
  created_at: '2026-10-01 10:00:00',
  age_days: 25,
  categories: { unanswered: true, pending_moderation: false, decision_due: true },
  author_type: null,
  board_frozen: false,
}

const IDEA_B = {
  id: 12,
  title: 'Pending idea from a guest',
  board_slug: 'roadmap-board',
  board_name: 'Roadmap',
  status: 'open',
  status_category: 'open',
  status_is_system: true,
  status_label_de: null,
  status_label_en: null,
  moderation_state: 'pending',
  up_count: 0,
  down_count: 0,
  voter_count: 0,
  created_at: '2026-10-20 08:00:00',
  age_days: 6,
  categories: { unanswered: false, pending_moderation: true, decision_due: false },
  author_type: 'guest',
  board_frozen: false,
}

const BOARDS = {
  boards: [
    { id: 1, slug: 'general', name: 'General' },
    { id: 2, slug: 'roadmap-board', name: 'Roadmap' },
  ],
}

function listBody(overrides: Record<string, unknown> = {}) {
  return {
    counts: COUNTS,
    board: null,
    ideas: [IDEA_A],
    next_cursor: null,
    ...overrides,
  }
}

interface MockResponse {
  status?: number
  body: unknown
}

type Handler = (url: URL) => MockResponse | undefined

/** URL-routed fetch mock; records every request URL. */
function mockFetch(handlers: Handler[], fallback?: Handler) {
  const calls: URL[] = []
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    const url = new URL(String(input), 'http://localhost')
    calls.push(url)
    for (const handler of handlers) {
      const hit = handler(url)
      if (hit) {
        return new Response(JSON.stringify(hit.body), {
          status: hit.status ?? 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
    }
    const hit = fallback?.(url)
    if (hit) {
      return new Response(JSON.stringify(hit.body), {
        status: hit.status ?? 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }
    return new Response(JSON.stringify({ error: { key: 'not_found', message: 'Not found' } }), {
      status: 404,
      headers: { 'Content-Type': 'application/json' },
    })
  })
  return calls
}

function worklistCalls(calls: URL[]) {
  return calls.filter((u) => u.pathname === '/admin/worklist')
}

function standardHandlers(
  list: MockResponse | ((url: URL) => MockResponse) = { body: listBody() },
) {
  return [
    (url: URL) => (url.pathname === '/api/bootstrap' ? { body: OWNER_BOOTSTRAP } : undefined),
    (url: URL) => (url.pathname === '/admin/boards' ? { body: BOARDS } : undefined),
    (url: URL) => {
      if (url.pathname === '/admin/worklist/summary') {
        return { body: { counts: COUNTS, board: null } }
      }
      return undefined
    },
    (url: URL) => {
      if (url.pathname !== '/admin/worklist') return undefined
      return typeof list === 'function' ? list(url) : list
    },
  ]
}

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/worklist']}>
      <Routes>
        <Route path="/admin/worklist" element={<WorklistPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
        <Route path="/general/idea/11" element={<div data-testid="idea-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  vi.restoreAllMocks()
})

describe('WorklistPage — loading and first load', () => {
  it('shows a loading state before the first response arrives', () => {
    mockFetch(standardHandlers())
    renderPage()
    expect(screen.getByTestId('worklist-loading')).toBeInTheDocument()
  })

  it('requests the list with the default filters', async () => {
    const calls = mockFetch(standardHandlers())
    renderPage()
    await waitFor(() => expect(screen.getByTestId('worklist-list')).toBeInTheDocument())
    const [first] = worklistCalls(calls)
    expect(first?.searchParams.get('category')).toBe('all')
    expect(first?.searchParams.get('age')).toBe('all')
    expect(first?.searchParams.get('sort')).toBe('oldest')
    expect(first?.searchParams.has('board')).toBe(false)
    expect(first?.searchParams.has('cursor')).toBe(false)
  })
})

describe('WorklistPage — header counts and rows', () => {
  it('shows the three header counts and the over-7-days caption', async () => {
    mockFetch(standardHandlers())
    renderPage()
    await waitFor(() => expect(screen.getByTestId('worklist-list')).toBeInTheDocument())

    expect(within(screen.getByTestId('worklist-count-unanswered')).getByText('4')).toBeVisible()
    expect(
      within(screen.getByTestId('worklist-count-unanswered')).getByText('1 älter als 7 Tage'),
    ).toBeVisible()
    expect(
      within(screen.getByTestId('worklist-count-pending-moderation')).getByText('2'),
    ).toBeVisible()
    expect(within(screen.getByTestId('worklist-count-decision-due')).getByText('1')).toBeVisible()
  })

  it('renders each row with a link to the idea and its reason badges', async () => {
    mockFetch(standardHandlers({ body: listBody() }))
    renderPage()
    const row = await screen.findByTestId('worklist-row-11')
    const link = within(row).getByRole('link', { name: 'Dark mode for the board' })
    expect(link).toHaveAttribute('href', '/general/idea/11')
    expect(within(row).getByTestId('worklist-row-11-link')).toBe(link)
    expect(within(row).getByText('Warten auf Antwort')).toBeInTheDocument()
    expect(within(row).getByText('Entscheidung fällig')).toBeInTheDocument()
    expect(within(row).getByText('12 dafür, 1 dagegen')).toBeInTheDocument()
  })

  it('shows the author type for a pending row with only moderation actions', async () => {
    mockFetch(
      standardHandlers({
        body: listBody({ ideas: [IDEA_B], counts: COUNTS }),
      }),
    )
    renderPage()
    const row = await screen.findByTestId('worklist-row-12')
    expect(within(row).getByText('Gast')).toBeInTheDocument()
    expect(within(row).getByText('Zur Moderation')).toBeInTheDocument()
    expect(within(row).getByTestId('worklist-row-12-approve')).toBeInTheDocument()
    expect(within(row).getByTestId('worklist-row-12-reject')).toBeInTheDocument()
    // Pending rows are decided by moderation, not by answer/status/merge/dismiss.
    expect(within(row).queryByTestId('worklist-row-12-answer')).not.toBeInTheDocument()
    expect(within(row).queryByTestId('worklist-row-12-dismiss')).not.toBeInTheDocument()
  })
})

describe('WorklistPage — pagination', () => {
  it('loads the next page with the cursor and appends its rows', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(
      standardHandlers((url) =>
        url.searchParams.get('cursor') === 'cursor-1'
          ? { body: listBody({ ideas: [IDEA_B], next_cursor: null }) }
          : { body: listBody({ next_cursor: 'cursor-1' }) },
      ),
    )
    renderPage()
    await screen.findByTestId('worklist-row-11')
    await user.click(screen.getByTestId('worklist-load-more'))

    await screen.findByTestId('worklist-row-12')
    expect(screen.getByTestId('worklist-row-11')).toBeInTheDocument()
    const second = worklistCalls(calls).at(-1)
    expect(second?.searchParams.get('cursor')).toBe('cursor-1')
    expect(second?.searchParams.get('category')).toBe('all')
    await waitFor(() => expect(screen.queryByTestId('worklist-load-more')).not.toBeInTheDocument())
  })
})

describe('WorklistPage — filters', () => {
  it('refetches with the reason filter and marks the chip as pressed', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')

    const chip = screen.getByTestId('worklist-filter-reason-pending-moderation')
    expect(chip).toHaveAttribute('aria-pressed', 'false')
    await user.click(chip)

    await waitFor(() =>
      expect(worklistCalls(calls).at(-1)?.searchParams.get('category')).toBe('pending_moderation'),
    )
    expect(chip).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByTestId('worklist-filter-reason-all')).toHaveAttribute(
      'aria-pressed',
      'false',
    )
  })

  it('refetches with the age filter over 7 days', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')
    await user.click(screen.getByTestId('worklist-filter-age-over-7-days'))
    await waitFor(() =>
      expect(worklistCalls(calls).at(-1)?.searchParams.get('age')).toBe('over_7_days'),
    )
  })

  it('refetches with the board slug and the votes sort', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')

    await user.selectOptions(screen.getByTestId('worklist-filter-board'), 'roadmap-board')
    await waitFor(() =>
      expect(worklistCalls(calls).at(-1)?.searchParams.get('board')).toBe('roadmap-board'),
    )

    await user.selectOptions(screen.getByTestId('worklist-filter-sort'), 'votes')
    await waitFor(() => expect(worklistCalls(calls).at(-1)?.searchParams.get('sort')).toBe('votes'))
    expect(worklistCalls(calls).at(-1)?.searchParams.get('board')).toBe('roadmap-board')
  })

  it('resets every filter with the reset button', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')
    expect(screen.queryByTestId('worklist-filter-reset')).not.toBeInTheDocument()

    await user.click(screen.getByTestId('worklist-filter-reason-unanswered'))
    await waitFor(() => expect(screen.getByTestId('worklist-filter-reset')).toBeInTheDocument())
    await user.click(screen.getByTestId('worklist-filter-reset'))

    await waitFor(() =>
      expect(worklistCalls(calls).at(-1)?.searchParams.get('category')).toBe('all'),
    )
    expect(screen.queryByTestId('worklist-filter-reset')).not.toBeInTheDocument()
  })

  it('announces the result count politely after a filter change', async () => {
    const user = userEvent.setup()
    mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')
    const announce = screen.getByTestId('worklist-announce')
    expect(announce).toHaveAttribute('aria-live', 'polite')
    expect(announce).toHaveTextContent('')

    await user.click(screen.getByTestId('worklist-filter-age-over-7-days'))
    await waitFor(() => expect(announce).toHaveTextContent('1 Ideen angezeigt.'))
  })
})

describe('WorklistPage — empty states', () => {
  it('says that nothing waits and shows the last check time', async () => {
    mockFetch(
      standardHandlers({
        body: listBody({
          counts: {
            unanswered: 0,
            unanswered_over_7_days: 0,
            pending_moderation: 0,
            decision_due: 0,
            total: 0,
          },
          ideas: [],
        }),
      }),
    )
    renderPage()
    const empty = await screen.findByTestId('worklist-empty')
    expect(within(empty).getByText('Im Moment wartet nichts auf dich.')).toBeInTheDocument()
    expect(within(empty).getByText(/Zuletzt geprüft:/)).toBeInTheDocument()
  })

  it('says that no idea matches when a filter is active, with a reset button', async () => {
    const user = userEvent.setup()
    const calls = mockFetch(
      standardHandlers((url) =>
        url.searchParams.get('category') === 'decision_due'
          ? { body: listBody({ ideas: [] }) }
          : { body: listBody() },
      ),
    )
    renderPage()
    await screen.findByTestId('worklist-row-11')
    await user.click(screen.getByTestId('worklist-filter-reason-decision-due'))

    const empty = await screen.findByTestId('worklist-empty')
    expect(within(empty).getByText('Keine Ideen passen zu diesen Filtern.')).toBeInTheDocument()
    await user.click(within(empty).getByTestId('worklist-empty-reset'))
    await waitFor(() =>
      expect(worklistCalls(calls).at(-1)?.searchParams.get('category')).toBe('all'),
    )
  })

  it('links to board creation when the account has no boards yet', async () => {
    mockFetch([
      (url: URL) => (url.pathname === '/api/bootstrap' ? { body: OWNER_BOOTSTRAP } : undefined),
      (url: URL) => (url.pathname === '/admin/boards' ? { body: { boards: [] } } : undefined),
      (url: URL) =>
        url.pathname === '/admin/worklist' ? { body: listBody({ ideas: [] }) } : undefined,
    ])
    renderPage()
    const empty = await screen.findByTestId('worklist-empty')
    expect(within(empty).getByText('Dieser Account hat noch keine Boards.')).toBeInTheDocument()
    expect(within(empty).getByTestId('worklist-empty-create-board')).toHaveAttribute(
      'href',
      '/admin/boards',
    )
  })
})

describe('WorklistPage — errors and access', () => {
  it('shows an error with retry when the list fails and renders no rows', async () => {
    const user = userEvent.setup()
    let attempt = 0
    mockFetch(
      standardHandlers(() => {
        attempt += 1
        return attempt === 1
          ? { status: 500, body: { error: { key: 'server_error', message: 'boom' } } }
          : { body: listBody() }
      }),
    )
    renderPage()
    const error = await screen.findByTestId('worklist-error')
    expect(error).toHaveAttribute('role', 'alert')
    expect(screen.queryByTestId('worklist-row-11')).not.toBeInTheDocument()
    expect(screen.queryByTestId('worklist-count-unanswered')).not.toBeInTheDocument()

    await user.click(within(error).getByTestId('worklist-error-retry'))
    await screen.findByTestId('worklist-row-11')
  })

  it('shows the no-access state when the list answers 403', async () => {
    mockFetch(
      standardHandlers({
        status: 403,
        body: { error: { key: 'forbidden', message: 'Forbidden' } },
      }),
    )
    renderPage()
    await waitFor(() => expect(screen.getByText('Kein Zugriff')).toBeInTheDocument())
    expect(screen.queryByTestId('worklist-list')).not.toBeInTheDocument()
  })

  it('redirects an anonymous visitor to login', async () => {
    mockFetch([
      (url: URL) =>
        url.pathname === '/api/bootstrap' ? { body: { csrf_token: 'x', user: null } } : undefined,
    ])
    renderPage()
    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('WorklistPage — navigation entry and badge', () => {
  it('shows the worklist entry with the distinct total as badge', async () => {
    mockFetch(standardHandlers())
    renderPage()
    const nav = await screen.findByTestId('nav-worklist')
    expect(nav).toHaveAttribute('href', '/admin/worklist')
    // counts.total, not the sum of the overlapping categories (4 + 2 + 1)
    await waitFor(() => expect(within(nav).getByText('6')).toBeInTheDocument())
  })

  it('does not show the worklist entry to a plain member', async () => {
    mockFetch([
      (url: URL) => (url.pathname === '/api/bootstrap' ? { body: MEMBER_BOOTSTRAP } : undefined),
      (url: URL) =>
        url.pathname === '/admin/worklist'
          ? { status: 403, body: { error: { key: 'forbidden', message: 'Forbidden' } } }
          : undefined,
      (url: URL) =>
        url.pathname === '/admin/boards'
          ? { status: 403, body: { error: { key: 'forbidden', message: 'Forbidden' } } }
          : undefined,
    ])
    renderPage()
    await waitFor(() => expect(screen.getByText('Kein Zugriff')).toBeInTheDocument())
    expect(screen.queryByTestId('nav-worklist')).not.toBeInTheDocument()
  })
})

describe('WorklistPage — testIDs', () => {
  it('renders the page landmarks and every interactive element of the read-only slice', async () => {
    mockFetch(standardHandlers({ body: listBody({ next_cursor: 'cursor-1' }) }))
    renderPage()
    await screen.findByTestId('worklist-row-11')

    for (const id of [
      'worklist-page',
      'worklist-heading',
      'worklist-count-unanswered',
      'worklist-count-pending-moderation',
      'worklist-count-decision-due',
      'worklist-filter-reason-all',
      'worklist-filter-reason-unanswered',
      'worklist-filter-reason-pending-moderation',
      'worklist-filter-reason-decision-due',
      'worklist-filter-age-all',
      'worklist-filter-age-over-7-days',
      'worklist-filter-board',
      'worklist-filter-sort',
      'worklist-list',
      'worklist-row-11',
      'worklist-row-11-link',
      'worklist-load-more',
    ]) {
      expect(screen.getByTestId(id), id).toBeInTheDocument()
    }
  })

  it('exposes the filter chips as a labelled group with aria-pressed state', async () => {
    mockFetch(standardHandlers())
    renderPage()
    await screen.findByTestId('worklist-row-11')
    const group = screen.getByRole('group', { name: 'Filter' })
    expect(group).toContainElement(screen.getByTestId('worklist-filter-reason-all'))
    expect(screen.getByTestId('worklist-filter-reason-all')).toHaveAttribute('aria-pressed', 'true')
  })
})

describe('WorklistPage — row actions (P-06 slice 04)', () => {
  it('approves a pending row via the moderation queue and removes it', async () => {
    const calls = mockFetch(
      standardHandlers({ body: listBody({ ideas: [IDEA_B], counts: COUNTS }) }),
      (url) =>
        url.pathname === '/admin/boards/roadmap-board/queue/approve'
          ? { body: { approved: [12] } }
          : undefined,
    )
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-12-approve'))
    await waitFor(() => expect(screen.queryByTestId('worklist-row-12')).not.toBeInTheDocument())
    expect(calls.some((u) => u.pathname === '/admin/boards/roadmap-board/queue/approve')).toBe(true)
  })

  it('dismisses an unanswered row, offers undo and restores the row on undo', async () => {
    const calls = mockFetch(standardHandlers({ body: listBody({ ideas: [IDEA_A] }) }), (url) => {
      if (url.pathname === '/admin/worklist/ideas/11/dismiss') {
        return { body: { ok: true, idea_id: 11, dismissed: true } }
      }
      if (url.pathname === '/admin/worklist/ideas/11/undismiss') {
        return { body: { ok: true, idea_id: 11, dismissed: false } }
      }
      return undefined
    })
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-dismiss'))
    await waitFor(() => expect(screen.queryByTestId('worklist-row-11')).not.toBeInTheDocument())
    expect(screen.getByTestId('worklist-undo-toast')).toBeInTheDocument()
    expect(calls.some((u) => u.pathname === '/admin/worklist/ideas/11/dismiss')).toBe(true)

    await user.click(screen.getByTestId('worklist-undo'))
    await waitFor(() => expect(screen.getByTestId('worklist-row-11')).toBeInTheDocument())
    expect(calls.some((u) => u.pathname === '/admin/worklist/ideas/11/undismiss')).toBe(true)
    expect(screen.queryByTestId('worklist-undo-toast')).not.toBeInTheDocument()
  })

  it('tells the admin shell that the worklist changed after a dismissal', async () => {
    mockFetch(standardHandlers({ body: listBody({ ideas: [IDEA_A] }) }), (url) =>
      url.pathname === '/admin/worklist/ideas/11/dismiss'
        ? { body: { ok: true, idea_id: 11, dismissed: true } }
        : undefined,
    )
    const dispatch = vi.spyOn(window, 'dispatchEvent')
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-dismiss'))
    await waitFor(() => expect(screen.queryByTestId('worklist-row-11')).not.toBeInTheDocument())
    expect(dispatch.mock.calls.some(([event]) => event.type === 'votepit:worklist-changed')).toBe(
      true,
    )
  })

  it('moves focus to the next row when a row leaves the list', async () => {
    mockFetch(standardHandlers({ body: listBody({ ideas: [IDEA_A, IDEA_B] }) }), (url) =>
      url.pathname === '/admin/worklist/ideas/11/dismiss'
        ? { body: { ok: true, idea_id: 11, dismissed: true } }
        : undefined,
    )
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-dismiss'))
    await waitFor(() => expect(screen.queryByTestId('worklist-row-11')).not.toBeInTheDocument())
    await waitFor(() => expect(screen.getByTestId('worklist-row-12-link')).toHaveFocus())
  })
})

// -- "Nur ausblenden": a separate action with the no-notification hint --------

describe('WorklistPage — Nur ausblenden (hide only)', () => {
  it('shows the hide action next to the decision and explains that nobody is notified', async () => {
    mockFetch(standardHandlers())
    renderPage()
    const row = await screen.findByTestId('worklist-row-11')

    const hide = within(row).getByTestId('worklist-row-11-dismiss')
    expect(hide).toHaveTextContent('Nur ausblenden')
    expect(hide).toHaveAttribute(
      'title',
      'Blendet die Idee nur in dieser Liste aus. Niemand wird benachrichtigt, die Abstimmung bleibt offen.',
    )
    expect(within(row).getByTestId('worklist-row-11-decide')).toHaveTextContent('Entscheiden')
  })
})

// -- "Entscheiden": status change with a mandatory explanation -----------------

function boardStatus(key: string, overrides: Record<string, unknown> = {}) {
  return {
    key,
    category: key,
    category_name: key,
    is_system: true,
    label_de: null,
    label_en: null,
    is_hidden: false,
    allow_votes: true,
    allow_comments: true,
    idea_count: null,
    ...overrides,
  }
}

const DECIDE_STATUSES = [
  boardStatus('open'),
  boardStatus('in_progress'),
  boardStatus('done', { allow_votes: false }),
  // Owner-defined status in the middle of the board order, with its own labels.
  boardStatus('s1a', {
    category: 'planned',
    is_system: false,
    label_de: 'Eingeplant intern',
    label_en: 'Scheduled internally',
  }),
  // Closes voting, but the server does not allow it as the next step from "open".
  boardStatus('declined', { allow_votes: false }),
  // Hidden statuses are never offered, even when the server allows them.
  boardStatus('s2b', { category: 'done', is_system: false, label_en: 'Hidden', is_hidden: true }),
]

/** IdeaDetailResponse subset the decision dialog reads: board statuses and allowed_next. */
const DECIDE_ALLOWED = ['in_progress', 'done', 's1a', 's2b']

/**
 * `allowedNext` null = the payload carries no allowed_next at all.
 * `reasonRequired` is the board setting status_reason_required (default: on).
 */
function ideaBody(
  statuses = DECIDE_STATUSES,
  allowedNext: string[] | null = DECIDE_ALLOWED,
  reasonRequired = true,
) {
  return {
    board: {
      id: 1,
      slug: 'general',
      name: 'General',
      statuses,
      status_reason_required: reasonRequired,
    },
    idea: {
      id: 11,
      status_key: 'open',
      ...(allowedNext === null ? {} : { allowed_next: allowedNext }),
    },
  }
}

function decideHandler(
  statuses?: typeof DECIDE_STATUSES,
  statusResponse?: MockResponse,
  allowedNext: string[] | null = DECIDE_ALLOWED,
  reasonRequired = true,
) {
  return (url: URL) => {
    if (url.pathname === '/general/ideas/11')
      return { body: ideaBody(statuses, allowedNext, reasonRequired) }
    if (url.pathname === '/general/ideas/11/status') {
      return (
        statusResponse ?? {
          body: { ok: true, status: 'done', status_key: 'done', allowed_next: [] },
        }
      )
    }
    return undefined
  }
}

describe('WorklistPage — Entscheiden (decision with a mandatory explanation)', () => {
  it('offers the statuses the server allows as the next step, in the board order', async () => {
    mockFetch(standardHandlers(), decideHandler())
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))

    const select = await screen.findByTestId('worklist-decide-status')
    const values = within(select)
      .getAllByRole('option')
      .map((option) => (option as HTMLOptionElement).value)
    // The current status ("open") and the hidden status are excluded; "declined" is not allowed from "open".
    expect(values).toEqual(['', 'in_progress', 'done', 's1a'])
  })

  it('labels every option with the board label, the default label of its category or the owner label', async () => {
    mockFetch(standardHandlers(), decideHandler())
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))

    const select = await screen.findByTestId('worklist-decide-status')
    const labels = within(select)
      .getAllByRole('option')
      .map((option) => option.textContent)
    expect(labels).toEqual(['Status wählen …', 'In Arbeit', 'Erledigt', 'Eingeplant intern'])
  })

  it('notes that a status closes voting only while such a status is picked', async () => {
    mockFetch(standardHandlers(), decideHandler())
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))
    const select = await screen.findByTestId('worklist-decide-status')

    expect(screen.queryByTestId('worklist-decide-closes-voting')).not.toBeInTheDocument()
    await user.selectOptions(select, 'in_progress')
    expect(screen.queryByTestId('worklist-decide-closes-voting')).not.toBeInTheDocument()
    await user.selectOptions(select, 'done')
    expect(screen.getByTestId('worklist-decide-closes-voting')).toHaveTextContent(
      'Schließt die Abstimmung für diese Idee.',
    )
    await user.selectOptions(select, 's1a')
    expect(screen.queryByTestId('worklist-decide-closes-voting')).not.toBeInTheDocument()
  })

  it('keeps the explanation optional when the board does not require one', async () => {
    mockFetch(standardHandlers(), decideHandler(undefined, undefined, DECIDE_ALLOWED, false))
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))
    await user.selectOptions(await screen.findByTestId('worklist-decide-status'), 'done')

    expect(
      screen.getByText(
        'Die Begründung ist optional. Wenn du eine angibst, ist sie öffentlich sichtbar; Autor, Abstimmende und Kommentierende werden benachrichtigt.',
      ),
    ).toBeInTheDocument()
    expect(
      screen.queryByText('Für diese Entscheidung ist eine Begründung Pflicht.'),
    ).not.toBeInTheDocument()
    expect(screen.getByTestId('worklist-decide-reason')).not.toBeRequired()
    expect(screen.getByTestId('worklist-decide-save')).toBeEnabled()
  })

  it('keeps the explanation mandatory: save stays disabled while it is empty or blank', async () => {
    mockFetch(standardHandlers(), decideHandler())
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))
    await user.selectOptions(await screen.findByTestId('worklist-decide-status'), 'done')

    const save = await screen.findByTestId('worklist-decide-save')
    expect(save).toBeDisabled()
    expect(
      screen.getByText(
        'Die Begründung ist Pflicht und öffentlich sichtbar; Autor, Abstimmende und Kommentierende werden benachrichtigt.',
      ),
    ).toBeInTheDocument()
    expect(
      screen.getByText('Für diese Entscheidung ist eine Begründung Pflicht.'),
    ).toBeInTheDocument()

    await user.type(screen.getByTestId('worklist-decide-reason'), '   ')
    expect(save).toBeDisabled()

    await user.type(screen.getByTestId('worklist-decide-reason'), 'Shipped in 2.4')
    expect(save).toBeEnabled()
  })

  it('saves the status with its explanation through the status endpoint and removes the row', async () => {
    mockFetch(standardHandlers(), decideHandler())
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))
    await user.selectOptions(await screen.findByTestId('worklist-decide-status'), 'done')
    await user.type(screen.getByTestId('worklist-decide-reason'), 'Shipped in the next release')
    await user.click(screen.getByTestId('worklist-decide-save'))

    await waitFor(() => expect(screen.queryByTestId('worklist-row-11')).not.toBeInTheDocument())
    const call = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(([input]) => String(input) === '/general/ideas/11/status')
    expect(call).toBeDefined()
    expect(JSON.parse(String(call?.[1]?.body))).toMatchObject({
      status: 'done',
      reason: 'Shipped in the next release',
    })
  })

  it('shows the server refusal in the dialog and keeps the row', async () => {
    mockFetch(
      standardHandlers(),
      decideHandler(undefined, {
        status: 423,
        body: {
          error: {
            key: 'board_frozen',
            message: 'This board is frozen (read-only) and cannot be edited at the moment.',
          },
        },
      }),
    )
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))
    await user.selectOptions(await screen.findByTestId('worklist-decide-status'), 'done')
    await user.type(screen.getByTestId('worklist-decide-reason'), 'Shipped')
    await user.click(screen.getByTestId('worklist-decide-save'))

    expect(await screen.findByTestId('worklist-decide-error')).toHaveTextContent(
      'This board is frozen',
    )
    expect(screen.getByTestId('worklist-row-11')).toBeInTheDocument()
  })

  it('says so when the server allows no next status', async () => {
    mockFetch(standardHandlers(), decideHandler(undefined, undefined, []))
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))

    expect(await screen.findByTestId('worklist-decide-no-targets')).toBeInTheDocument()
    expect(screen.queryByTestId('worklist-decide-status')).not.toBeInTheDocument()
  })

  it('fails closed: without allowed_next nothing is offered', async () => {
    mockFetch(standardHandlers(), decideHandler(undefined, undefined, null))
    const user = userEvent.setup()
    renderPage()
    await user.click(await screen.findByTestId('worklist-row-11-decide'))

    expect(await screen.findByTestId('worklist-decide-no-targets')).toBeInTheDocument()
    expect(screen.queryByTestId('worklist-decide-status')).not.toBeInTheDocument()
  })
})

describe('WorklistPage — row status label', () => {
  it('shows the default label of a default-board status', async () => {
    mockFetch(standardHandlers())
    renderPage()
    const row = await screen.findByTestId('worklist-row-11')

    expect(within(row).getByText('Offen')).toBeInTheDocument()
  })

  it('shows the board label of an owner-defined status instead of its raw key', async () => {
    const custom = {
      ...IDEA_A,
      status: 's1a',
      status_category: 'open',
      status_is_system: false,
      status_label_de: 'Eingegangen',
      status_label_en: 'Received',
    }
    mockFetch(standardHandlers({ body: listBody({ ideas: [custom] }) }))
    renderPage()
    const row = await screen.findByTestId('worklist-row-11')

    expect(within(row).getByText('Eingegangen')).toBeInTheDocument()
    expect(within(row).queryByText('s1a')).not.toBeInTheDocument()
  })
})

// -- Frozen boards: no decision and no hiding ---------------------------------

describe('WorklistPage — frozen board rows', () => {
  it('hides the decision and the hide action, keeps the answer link and names the reason', async () => {
    mockFetch(standardHandlers({ body: listBody({ ideas: [{ ...IDEA_A, board_frozen: true }] }) }))
    renderPage()
    const row = await screen.findByTestId('worklist-row-11')

    expect(within(row).queryByTestId('worklist-row-11-decide')).not.toBeInTheDocument()
    expect(within(row).queryByTestId('worklist-row-11-dismiss')).not.toBeInTheDocument()
    expect(within(row).getByTestId('worklist-row-11-frozen')).toHaveTextContent('eingefroren')
    expect(within(row).getByTestId('worklist-row-11-answer')).toBeInTheDocument()
  })
})
