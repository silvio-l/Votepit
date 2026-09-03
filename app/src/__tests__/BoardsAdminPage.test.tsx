/**
 * RTL tests for BoardsAdminPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Admin sees the board list rendered from the mocked API, with links to
 *     the existing board-scoped admin pages
 *  3. Non-admin (is_admin: false from bootstrap) → access denied message
 *  4. Anon (user: null from bootstrap) → redirect to /login
 *  5. Create-form: slug auto-suggestion while typing the name (Issue 03)
 *  6. Create-form: field-error mapping from the API's 422 response
 *  7. Create-form: double-submit lock (button disabled + aria-busy while pending)
 *  8. Create-form: redirect to /admin/boards/{slug} on success
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { setAccountSlug } from '../lib/accountContext'
import BoardsAdminPage from '../pages/BoardsAdminPage'

// ── Mock data ─────────────────────────────────────────────────────────────────

const ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: true },
}

// Authenticated but not an owner/moderator of this account (server 403s
// listAdminBoards) — the platform is_admin flag is deliberately also false
// here, since it's irrelevant to this gate (see api.ts accountRoleFor).
const NON_ADMIN_BOOTSTRAP = {
  csrf_token: 'test-csrf',
  user: { id: 2, is_admin: false, is_operator: false, memberships: [] },
}
const FORBIDDEN_RESPONSE = { error: { key: 'account_forbidden', message: 'Kein Zugriff.' } }

// account.onboarding_completed_at is non-null — these tests exercise the
// established-account board list, not the first-run Setup Wizard (see the
// "Setup Wizard (onboarding)" describe block below for that).
const BOARDS_RESPONSE = {
  boards: [
    { id: 1, slug: 'alpha', name: 'Alpha Board', frozen_at: null, idea_count: 0, vote_count: 0 },
    { id: 2, slug: 'beta', name: 'Beta Board', frozen_at: null, idea_count: 0, vote_count: 0 },
  ],
  account: { onboarding_completed_at: '2020-01-01T00:00:00+00:00' },
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/** Build a sequential fetch mock from a list of response payloads. */
function makeFetchMock(responses: Array<{ body: object; status?: number }>) {
  let callIndex = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
    // The header's notification bell fires its own GET /notifications on every
    // authenticated page — served out-of-band so it never consumes a slot from
    // this page's own response queue below.
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

function renderBoardsAdminPage() {
  return render(
    <MemoryRouter initialEntries={['/admin/boards']}>
      <Routes>
        <Route path="/admin/boards" element={<BoardsAdminPage />} />
        <Route path="/admin/boards/:slug" element={<div data-testid="board-detail-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

/** A promise you can resolve from the outside — for pending-state assertions. */
function makeDeferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((res) => {
    resolve = res
  })
  return { promise, resolve }
}

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
})

afterEach(() => {
  setAccountSlug(null)
})

describe('BoardsAdminPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: BOARDS_RESPONSE }])

    renderBoardsAdminPage()

    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('BoardsAdminPage — board list', () => {
  it('renders the boards from the API with links to their admin pages', async () => {
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: BOARDS_RESPONSE }])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByText('Alpha Board')).toBeInTheDocument())
    expect(screen.getByText('Beta Board')).toBeInTheDocument()
    expect(screen.getByText('alpha')).toBeInTheDocument()
    expect(screen.getByText('beta')).toBeInTheDocument()

    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/admin/boards/alpha')
    expect(hrefs).toContain('/admin/boards/beta')
  })

  it('prefixes the Members/Account links with the account slug in cloud mode', async () => {
    setAccountSlug('acme')
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: BOARDS_RESPONSE }])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByText('Alpha Board')).toBeInTheDocument())

    const links = screen.getAllByRole('link')
    const hrefs = links.map((l) => l.getAttribute('href'))
    expect(hrefs).toContain('/acme/admin/members')
    expect(hrefs).toContain('/acme/admin/account')
  })

  it('marks a frozen board (downgrade freeze) without hiding it', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          boards: [
            {
              id: 1,
              slug: 'alpha',
              name: 'Alpha Board',
              frozen_at: '2026-01-01 00:00:00',
              idea_count: 0,
              vote_count: 0,
            },
            {
              id: 2,
              slug: 'beta',
              name: 'Beta Board',
              frozen_at: null,
              idea_count: 0,
              vote_count: 0,
            },
          ],
          account: { onboarding_completed_at: '2020-01-01T00:00:00+00:00' },
        },
      },
    ])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByText('Alpha Board')).toBeInTheDocument())
    expect(screen.getByText('Eingefroren')).toBeInTheDocument()
    // Still linked/manageable, not hidden.
    expect(screen.getByText('Beta Board')).toBeInTheDocument()
    const links = screen.getAllByRole('link')
    expect(links.map((l) => l.getAttribute('href'))).toContain('/admin/boards/alpha')
  })
})

describe('BoardsAdminPage — access denied', () => {
  it('shows no-access message for a user who is not a member of this account', async () => {
    makeFetchMock([{ body: NON_ADMIN_BOOTSTRAP }, { body: FORBIDDEN_RESPONSE, status: 403 }])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())

    expect(screen.getByText('Kein Zugriff')).toBeInTheDocument()
    expect(screen.queryByRole('list', { name: 'Boards' })).not.toBeInTheDocument()
  })

  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('BoardsAdminPage — create-form: slug auto-suggestion', () => {
  it('proposes a slug from the name while typing, editable afterwards', async () => {
    makeFetchMock([{ body: ADMIN_BOOTSTRAP }, { body: BOARDS_RESPONSE }])

    renderBoardsAdminPage()
    await waitFor(() => expect(screen.getByLabelText(/^Name/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/^Name/i), 'Produkt Feedback')

    const slugInput = screen.getByLabelText(/^Slug/i) as HTMLInputElement
    await waitFor(() => expect(slugInput.value).toBe('produkt-feedback'))

    // Manual edit of the slug must stick — further name typing shouldn't override it.
    await user.clear(slugInput)
    await user.type(slugInput, 'custom-slug')
    await user.type(screen.getByLabelText(/^Name/i), '!')

    expect(slugInput.value).toBe('custom-slug')
  })
})

describe('BoardsAdminPage — create-form: field-error mapping', () => {
  it('shows the slug field error returned by the API', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BOARDS_RESPONSE },
      {
        body: {
          error: {
            key: 'validation_error',
            message: 'Validation failed.',
            fields: { slug: 'Dieser Slug ist in deinem Account bereits vergeben.' },
          },
        },
        status: 422,
      },
    ])

    renderBoardsAdminPage()
    await waitFor(() => expect(screen.getByLabelText(/^Name/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/^Name/i), 'Alpha Board')
    await user.click(screen.getByRole('button', { name: /Board anlegen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Dieser Slug ist in deinem Account bereits vergeben.'),
      ).toBeInTheDocument(),
    )
  })
})

describe('BoardsAdminPage — create-form: double-submit lock', () => {
  it('disables the submit button and sets aria-busy while the request is pending', async () => {
    let callIndex = 0
    let createCallCount = 0
    const deferred = makeDeferred<Response>()

    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      // The header's notification bell fires its own GET /notifications on
      // every authenticated page — served out-of-band so it never shifts the
      // call-index sequence below.
      if (typeof input === 'string' && input.startsWith('/notifications')) {
        return new Response(JSON.stringify({ notifications: [] }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      callIndex++
      if (callIndex === 1) {
        return new Response(JSON.stringify(ADMIN_BOOTSTRAP), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      if (callIndex === 2) {
        return new Response(JSON.stringify(BOARDS_RESPONSE), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      // ActivationChecklist fetches the member list on mount (call 3) —
      // distinct from the create-board POST this test is actually about.
      if (callIndex === 3) {
        return new Response(JSON.stringify({ members: [], invites: [], viewer_role: 'owner' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      }
      createCallCount++
      return deferred.promise
    })

    renderBoardsAdminPage()
    await waitFor(() => expect(screen.getByLabelText(/^Name/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/^Name/i), 'Pending Board')
    const submitButton = screen.getByRole('button', { name: /Board anlegen/i })
    await user.click(submitButton)

    await waitFor(() => expect(submitButton).toBeDisabled())
    expect(submitButton).toHaveAttribute('aria-busy', 'true')

    // A second click while pending must not fire a second create request
    // (the button is disabled — user-event will not dispatch a click on it).
    await user.click(submitButton)
    expect(createCallCount).toBe(1)

    deferred.resolve(
      new Response(JSON.stringify({ ok: true, slug: 'pending-board', name: 'Pending Board' }), {
        status: 201,
        headers: { 'Content-Type': 'application/json' },
      }),
    )

    await waitFor(() => expect(screen.getByTestId('board-detail-page')).toBeInTheDocument())
  })
})

describe('BoardsAdminPage — create-form: redirect on success', () => {
  it('navigates to /admin/boards/{slug} after a successful create', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: BOARDS_RESPONSE },
      { body: { ok: true, slug: 'new-board', name: 'New Board' }, status: 201 },
    ])

    renderBoardsAdminPage()
    await waitFor(() => expect(screen.getByLabelText(/^Name/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/^Name/i), 'New Board')
    await user.click(screen.getByRole('button', { name: /Board anlegen/i }))

    await waitFor(() => expect(screen.getByTestId('board-detail-page')).toBeInTheDocument())
  })
})

describe('BoardsAdminPage — Setup Wizard (onboarding)', () => {
  it('shows the wizard instead of the board list for a not-yet-onboarded account with no boards', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { boards: [], account: { onboarding_completed_at: null } } },
    ])

    renderBoardsAdminPage()

    await waitFor(() => expect(screen.getByText(/Willkommen bei Votepit/i)).toBeInTheDocument())
    expect(screen.queryByText(/Noch keine Boards angelegt\./)).not.toBeInTheDocument()
  })

  it('resumes straight at the "ready" step when a board already exists but onboarding is unfinished', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      {
        body: {
          boards: [
            {
              id: 1,
              slug: 'alpha',
              name: 'Alpha Board',
              frozen_at: null,
              idea_count: 0,
              vote_count: 0,
            },
          ],
          account: { onboarding_completed_at: null },
        },
      },
    ])

    renderBoardsAdminPage()

    await waitFor(() =>
      expect(screen.getByText(/„Alpha Board" ist startklar/i)).toBeInTheDocument(),
    )
    expect(screen.queryByText(/Willkommen bei Votepit/i)).not.toBeInTheDocument()
  })

  it('skipping the wizard calls the complete-onboarding endpoint and shows the normal board list', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { boards: [], account: { onboarding_completed_at: null } } },
      { body: { ok: true } }, // POST /admin/onboarding/complete
    ])

    renderBoardsAdminPage()
    await waitFor(() => expect(screen.getByText(/Willkommen bei Votepit/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Später einrichten/i }))

    await waitFor(() =>
      expect(screen.getByText(/Noch keine Boards angelegt\./)).toBeInTheDocument(),
    )
  })

  it('does not show the wizard for an already-onboarded account, even with no boards yet', async () => {
    makeFetchMock([
      { body: ADMIN_BOOTSTRAP },
      { body: { boards: [], account: { onboarding_completed_at: '2020-01-01T00:00:00+00:00' } } },
    ])

    renderBoardsAdminPage()

    await waitFor(() =>
      expect(screen.getByText(/Noch keine Boards angelegt\./)).toBeInTheDocument(),
    )
    expect(screen.queryByText(/Willkommen bei Votepit/i)).not.toBeInTheDocument()
  })
})
