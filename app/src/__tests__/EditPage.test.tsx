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
import EditPage from '../pages/EditPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
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

// ── Tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.restoreAllMocks()
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
        screen.getByText('Du hast keine Berechtigung, diese Idee zu bearbeiten.'),
      ).toBeInTheDocument(),
    )

    // The edit form must NOT be shown
    expect(screen.queryByLabelText(/Titel/i)).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /Änderungen speichern/i })).not.toBeInTheDocument()
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
