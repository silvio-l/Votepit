/**
 * RTL tests for SubmitPage — user-visible behaviour only (AC4).
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Inline field errors when API returns 422 with field-level messages
 *  2. General error when API returns 422 with no field errors (e.g. moderation block)
 *  3. Successful submit calls createIdea and navigates to idea detail
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SubmitPage from '../pages/SubmitPage'

// ── Mock helpers ──────────────────────────────────────────────────────────────

const BOOTSTRAP_RESPONSE = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
}
const FORM_DATA_RESPONSE = {
  board: { id: 1, slug: 'demo', name: 'Demo Board' },
  is_authenticated: true,
  form_at: 'fake-stamp',
}

function makeFetchMock(submitResponse: object, submitStatus = 200) {
  let callIndex = 0
  const responses = [
    { body: JSON.stringify(BOOTSTRAP_RESPONSE), status: 200 },
    { body: JSON.stringify(FORM_DATA_RESPONSE), status: 200 },
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

function renderSubmitPage(boardSlug = 'demo') {
  return render(
    <MemoryRouter initialEntries={[`/${boardSlug}/submit`]}>
      <Routes>
        <Route path="/:boardSlug/submit" element={<SubmitPage />} />
        {/* Target after success navigate */}
        <Route path="/:boardSlug/idea/:ideaId" element={<div data-testid="idea-detail" />} />
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

describe('SubmitPage — inline validation', () => {
  it('shows title field error when API returns 422 with title field message', async () => {
    makeFetchMock(
      {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { title: 'Der Titel muss mindestens 3 Zeichen lang sein.' },
          values: { title: 'ab' },
        },
      },
      422,
    )

    renderSubmitPage()

    // Wait for form to load
    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'ab')
    await user.type(screen.getByLabelText(/Beschreibung/i), 'Eine Beschreibung die lang genug ist.')
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Der Titel muss mindestens 3 Zeichen lang sein.'),
      ).toBeInTheDocument(),
    )

    // Body field should NOT show an error
    expect(screen.queryByText(/Beschreibung darf nicht leer sein/i)).not.toBeInTheDocument()
  })

  it('shows body field error when API returns 422 with body field message', async () => {
    makeFetchMock(
      {
        error: {
          key: 'validation_error',
          message: 'Validation failed.',
          fields: { body: 'Die Beschreibung darf nicht leer sein.' },
          values: { title: 'Gültiger Titel', body: '' },
        },
      },
      422,
    )

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Gültiger Titel')
    // Leave body empty — submit anyway
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(screen.getByText('Die Beschreibung darf nicht leer sein.')).toBeInTheDocument(),
    )
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

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Titel mit problematischem Inhalt')
    await user.type(screen.getByLabelText(/Beschreibung/i), 'Beschreibung mit Problemen.')
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    await waitFor(() =>
      expect(screen.getByRole('alert')).toHaveTextContent(
        'Dein Text enthält unzulässige Begriffe. Bitte formuliere ihn um.',
      ),
    )
  })
})

describe('SubmitPage — success flow', () => {
  it('calls createIdea and navigates to idea detail on 201', async () => {
    makeFetchMock({ ok: true, id: 42 }, 201)

    renderSubmitPage()

    await waitFor(() => expect(screen.getByLabelText(/Titel/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Titel/i), 'Meine tolle Idee')
    await user.type(
      screen.getByLabelText(/Beschreibung/i),
      'Eine ausführliche Beschreibung der Idee.',
    )
    await user.click(screen.getByRole('button', { name: /Idee einreichen/i }))

    // Should navigate to idea detail — the route renders our stub
    await waitFor(() => expect(screen.getByTestId('idea-detail')).toBeInTheDocument())
  })
})

describe('SubmitPage — anon redirect', () => {
  it('redirects anon user to login with return-to', async () => {
    let callIndex = 0
    const responses = [
      { body: JSON.stringify({ csrf_token: 'test', user: null }), status: 200 },
      {
        body: JSON.stringify({
          board: { id: 1, slug: 'demo', name: 'Demo' },
          is_authenticated: false,
          form_at: '',
        }),
        status: 200,
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

    renderSubmitPage()

    // Should navigate to the login page
    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})
