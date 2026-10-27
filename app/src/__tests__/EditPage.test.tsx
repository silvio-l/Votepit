/**
 * RTL tests for EditPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  AC1: Author sees pre-filled form (title+body), submit calls updateIdea,
 *       success navigates to idea detail.
 *  AC2: Author withdraws (calls withdrawIdea hard-delete, navigates to board).
 *  AC3: Non-author / 403 path: no edit form shown, error message displayed.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { setVoterPreview } from '../lib/voterPreview'
import EditPage from '../pages/EditPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}
const OWNER_BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: {
    id: 1,
    is_admin: false,
    is_operator: false,
    memberships: [{ account_slug: 'demo', role: 'owner' }],
  },
}

const EDIT_DATA_RESPONSE = {
  board: { id: 1, slug: 'demo', name: 'Demo Board' },
  idea: {
    id: 42,
    board_id: 1,
    author_id: 1,
    title: 'Ursprünglicher Titel',
    body: 'Ursprüngliche Beschreibung der Idee.',
    status: 'open',
    score_cache: 3,
    up_count: 4,
    down_count: 1,
    comment_count: 0,
    created_at: '2025-06-01 10:00:00',
    updated_at: '2025-06-01 10:00:00',
  },
  is_authenticated: true,
  form_at: 'fake-stamp',
}

/**
 * Mock fetch with sequential responses:
 *  0: /api/bootstrap
 *  1: /{boardSlug}/ideas/{id}/edit  (GET)
 *  2: /{boardSlug}/ideas/{id}       (POST — update)
 */
function makeFetchMock(submitResponse: object, submitStatus = 200) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
    { body: JSON.stringify(EDIT_DATA_RESPONSE), status: 200 },
    { body: JSON.stringify(submitResponse), status: submitStatus },
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

/**
 * Mock fetch for 403 (non-owner) path:
 *  0: /api/bootstrap
 *  1: /{boardSlug}/ideas/{id}/edit  (GET → 403)
 */
function makeForbiddenFetchMock() {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
    {
      body: JSON.stringify({ error: { key: 'forbidden', message: 'Zugriff verweigert.' } }),
      status: 403,
    },
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

function renderEditPage(boardSlug = 'demo', ideaId = '42') {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/idea/${ideaId}/edit`]}>
      <Routes>
        <Route path="/:boardSlug/idea/:ideaId/edit" element={<EditPage />} />
        {/* Target after successful edit */}
        <Route path="/:boardSlug/idea/:ideaId" element={<div data-testid="idea-detail" />} />
        {/* Target after withdraw */}
        <Route path="/:boardSlug" element={<div data-testid="board-page" />} />
        {/* Login redirect target */}
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

function renderEditPageAt(entry: string) {
  return render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/:boardSlug/idea/:ideaId/edit" element={<EditPage />} />
        <Route path="/:boardSlug/idea/:ideaId" element={<div data-testid="idea-detail" />} />
        <Route path="/:boardSlug" element={<div data-testid="board-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

function makeOwnerFetchMock() {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(OWNER_BOOTSTRAP_RESPONSE), status: 200 },
    { body: JSON.stringify(EDIT_DATA_RESPONSE), status: 200 },
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

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
  setVoterPreview(false)
})

describe('EditPage — tags (P-34, same picker as submit)', () => {
  it('sends the selected tag ids with the update', async () => {
    const bug = { id: 1, name: 'Bug', color: '#c0392b' }
    const ux = { id: 2, name: 'UX', color: '#2980b9' }
    const editWithTags = {
      ...EDIT_DATA_RESPONSE,
      tags: [bug, ux],
      idea: { ...EDIT_DATA_RESPONSE.idea, tags: [bug] },
    }
    const responses = [
      { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
      { body: JSON.stringify(editWithTags), status: 200 },
      { body: JSON.stringify({ ok: true }), status: 200 },
    ]
    let callIndex = 0
    const spy = vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderEditPage()
    await waitFor(() => expect(screen.getByTestId('edit-tag-picker')).toBeInTheDocument())
    expect(screen.getByTestId('edit-tag-1')).toHaveAttribute('aria-pressed', 'true')

    const user = userEvent.setup()
    await user.click(screen.getByTestId('edit-tag-2'))
    await user.click(screen.getByRole('button', { name: /Änderungen speichern/i }))

    await waitFor(() => expect(screen.getByTestId('idea-detail')).toBeInTheDocument())
    const post = spy.mock.calls.find(([, init]) => init?.method === 'POST')
    expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({ tag_ids: [1, 2] })
  })
})

describe('EditPage — pre-filled form (AC1)', () => {
  it('renders pre-filled title and body from API response', async () => {
    makeFetchMock({ ok: true })

    renderEditPage()

    await waitFor(() =>
      expect(screen.getByDisplayValue('Ursprünglicher Titel')).toBeInTheDocument(),
    )
    expect(screen.getByDisplayValue('Ursprüngliche Beschreibung der Idee.')).toBeInTheDocument()
  })

  it('shows inline title field error when server returns 422 with field message', async () => {
    makeFetchMock(
      {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { title: 'Der Titel muss mindestens 3 Zeichen lang sein.' },
          values: { title: 'ab', body: 'ok' },
        },
      },
      422,
    )

    renderEditPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.clear(screen.getByLabelText(/Titel/i))
    await user.type(screen.getByLabelText(/Titel/i), 'ab')
    await user.click(screen.getByRole('button', { name: /Änderungen speichern/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Der Titel muss mindestens 3 Zeichen lang sein.'),
      ).toBeInTheDocument(),
    )
  })

  it('calls updateIdea and navigates to idea detail on success', async () => {
    makeFetchMock({ ok: true }, 200)

    renderEditPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.clear(screen.getByLabelText(/Titel/i))
    await user.type(screen.getByLabelText(/Titel/i), 'Aktualisierter Titel')
    await user.clear(screen.getByLabelText(/Beschreibung/i))
    await user.type(screen.getByLabelText(/Beschreibung/i), 'Neue Beschreibung der Idee.')
    await user.click(screen.getByRole('button', { name: /Änderungen speichern/i }))

    await waitFor(() => expect(screen.getByTestId('idea-detail')).toBeInTheDocument())
  })

  it('shows general error for non-field 422 (e.g. moderation block)', async () => {
    makeFetchMock(
      {
        error: {
          key: 'moderation_blocked',
          message: 'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
          fields: {},
        },
      },
      422,
    )

    renderEditPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.click(screen.getByRole('button', { name: /Änderungen speichern/i }))

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
      ),
    )
  })
})

describe('EditPage — withdraw (AC2)', () => {
  it('withdraw button is NOT present on the EditPage itself', async () => {
    makeFetchMock({ ok: true })

    renderEditPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    // The withdraw action lives on IdeaDetailPage, not EditPage
    expect(screen.queryByText(/zurückziehen/i)).not.toBeInTheDocument()
  })
})

describe('EditPage — non-author / 403 (AC3)', () => {
  it('shows forbidden error when server returns 403 on GET /edit', async () => {
    makeForbiddenFetchMock()

    renderEditPage()

    await waitFor(() =>
      expect(
        screen.getByText('Nur wer eine Idee eingereicht hat, kann sie bearbeiten.'),
      ).toBeInTheDocument(),
    )

    // The edit form must NOT be shown
    expect(screen.queryByLabelText(/Titel/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Änderungen speichern/i })).not.toBeInTheDocument()
  })
})

describe('EditPage — edit window expired', () => {
  it('shows the expired-window error when server returns 422 edit_window_expired on GET /edit', async () => {
    let callIndex = 0
    const responses = [
      { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
      {
        body: JSON.stringify({
          error: {
            key: 'edit_window_expired',
            message: 'This idea can no longer be edited.',
          },
        }),
        status: 422,
      },
    ]

    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderEditPage()

    await waitFor(() =>
      expect(screen.getByText('Bearbeitungsfrist abgelaufen')).toBeInTheDocument(),
    )

    // The edit form must NOT be shown
    expect(screen.queryByLabelText(/Titel/i)).not.toBeInTheDocument()
  })
})

describe('EditPage — 401 redirect (AC3)', () => {
  it('redirects anon user to login when server returns 401 on GET /edit', async () => {
    let callIndex = 0
    const responses = [
      { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
      {
        body: JSON.stringify({
          error: { key: 'unauthenticated', message: 'Login erforderlich.' },
        }),
        status: 401,
      },
    ]

    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      const r = responses[callIndex] ?? responses[responses.length - 1]
      callIndex++
      return new Response(r.body, {
        status: r.status,
        headers: { 'Content-Type': 'application/json' },
      })
    })

    renderEditPage()

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('EditPage — voter-preview propagation', () => {
  it('landing with ?view=voter hides the Settings link for an owner editing their own idea', async () => {
    makeOwnerFetchMock()

    renderEditPageAt('/demo/idea/42/edit?view=voter')

    await waitFor(() =>
      expect(screen.getByDisplayValue('Ursprünglicher Titel')).toBeInTheDocument(),
    )
    expect(screen.queryByRole('link', { name: 'Einstellungen' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Voter-Vorschau beenden' })).toBeInTheDocument()
  })

  it('owner without the preview toggle still sees the Settings link', async () => {
    makeOwnerFetchMock()

    renderEditPage()

    await waitFor(() =>
      expect(screen.getByDisplayValue('Ursprünglicher Titel')).toBeInTheDocument(),
    )
    expect(screen.getByRole('link', { name: 'Einstellungen' })).toBeInTheDocument()
  })
})

describe('EditPage — board look (branding)', () => {
  function mockBoardLook(boardVisual: object) {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = typeof input === 'string' ? input : (input as Request).url
      const json = (body: object) =>
        new Response(JSON.stringify(body), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        })
      if (url.includes('/api/bootstrap')) return json(BOOTSTRAP_RESPONSE)
      return json({
        ...EDIT_DATA_RESPONSE,
        board: { ...EDIT_DATA_RESPONSE.board, ...boardVisual },
      })
    })
  }

  it('applies the board colours and logo on the edit page', async () => {
    mockBoardLook({
      primary_color: '#00AA55',
      secondary_color: '#112233',
      logo_url: 'https://example.com/edit-logo.png',
    })

    const { container } = renderEditPage()
    await screen.findByDisplayValue('Ursprünglicher Titel')

    const branded = container.querySelector('[style*="--vp-primary"]') as HTMLElement
    expect(branded.style.getPropertyValue('--vp-primary')).toBe('#00AA55')
    expect(branded.style.getPropertyValue('--vp-ink')).toBe('#112233')
    expect(
      container.querySelectorAll('img[src="https://example.com/edit-logo.png"]').length,
    ).toBeGreaterThan(0)
  })

  it('applies no board colours or logo when the plan withholds them (Free)', async () => {
    mockBoardLook({ primary_color: null, secondary_color: null, logo_url: null })

    const { container } = renderEditPage()
    await screen.findByDisplayValue('Ursprünglicher Titel')

    expect(container.querySelector('[style*="--vp-primary"]')).toBeNull()
    expect(container.querySelectorAll('img').length).toBe(0)
  })
})
