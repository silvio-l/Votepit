/**
 * RTL tests for AppealPage — user-visible behaviour only.
 *
 * fetch is mocked globally; no real network calls are made.
 * Tests cover:
 *  1. Loading state before bootstrap resolves
 *  2. Anon (user: null from bootstrap) → redirect to /login
 *  3. Successful submit shows the "submitted" confirmation
 *  4. 403 (non-author) → error state
 *  5. 404 (unknown/cross-account moderation action) → error state
 *  6. 409 (already-pending appeal) → inline error, form stays visible
 *  7. 422 (validation) → inline field error on the message textarea
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import AppealPage from '../pages/AppealPage'

const BOOTSTRAP_OK = {
  csrf_token: 'test-csrf',
  user: { id: 1, is_admin: false },
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

function renderAppealPage() {
  return render(
    <MemoryRouter initialEntries={['/appeal/42']}>
      <Routes>
        <Route path="/appeal/:moderationActionId" element={<AppealPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('AppealPage — loading', () => {
  it('shows a loading indicator before bootstrap resolves', () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }])
    renderAppealPage()
    expect(screen.getByText(/Wird geladen/i)).toBeInTheDocument()
  })
})

describe('AppealPage — anon', () => {
  it('redirects anon user (user: null) to login with return-to', async () => {
    makeFetchMock([{ body: { csrf_token: 'test', user: null } }])
    renderAppealPage()
    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})

describe('AppealPage — submit', () => {
  it('shows the confirmation after a successful submit', async () => {
    makeFetchMock([{ body: BOOTSTRAP_OK }, { body: { ok: true, id: 7 }, status: 201 }])

    renderAppealPage()
    await waitFor(() => expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(
      screen.getByLabelText(/Warum sollte/i),
      'This was clearly not against the rules.',
    )
    await user.click(screen.getByRole('button', { name: /Einspruch einreichen/i }))

    await waitFor(() => expect(screen.getByText('Einspruch eingereicht')).toBeInTheDocument())
  })

  it('shows an error state for a non-author 403', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      {
        body: { error: { key: 'forbidden', message: 'Only the original author can appeal.' } },
        status: 403,
      },
    ])

    renderAppealPage()
    await waitFor(() => expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Warum sollte/i), 'I want to appeal this removal.')
    await user.click(screen.getByRole('button', { name: /Einspruch einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText(/Nur die ursprüngliche Autorin\/der ursprüngliche Autor/i),
      ).toBeInTheDocument(),
    )
  })

  it('shows a not-found error state for an unknown moderation action', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      { body: { error: { key: 'not_found', message: 'Not found.' } }, status: 404 },
    ])

    renderAppealPage()
    await waitFor(() => expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Warum sollte/i), 'I want to appeal this removal.')
    await user.click(screen.getByRole('button', { name: /Einspruch einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Diese Entfernung konnte nicht gefunden werden.'),
      ).toBeInTheDocument(),
    )
  })

  it('shows an inline error for an already-pending appeal (409), form stays visible', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      {
        body: { error: { key: 'appeal_already_pending', message: 'Already pending.' } },
        status: 409,
      },
    ])

    renderAppealPage()
    await waitFor(() => expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Warum sollte/i), 'I want to appeal this removal.')
    await user.click(screen.getByRole('button', { name: /Einspruch einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Für diese Entfernung liegt bereits ein Einspruch zur Prüfung vor.'),
      ).toBeInTheDocument(),
    )
    expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument()
  })

  it('shows the field error returned by a 422 validation failure', async () => {
    makeFetchMock([
      { body: BOOTSTRAP_OK },
      {
        body: {
          error: {
            key: 'validation_error',
            message: 'Validation failed.',
            fields: { message: 'Please describe your appeal in a bit more detail.' },
          },
        },
        status: 422,
      },
    ])

    renderAppealPage()
    await waitFor(() => expect(screen.getByLabelText(/Warum sollte/i)).toBeInTheDocument())

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/Warum sollte/i), 'too short')
    await user.click(screen.getByRole('button', { name: /Einspruch einreichen/i }))

    await waitFor(() =>
      expect(
        screen.getByText('Please describe your appeal in a bit more detail.'),
      ).toBeInTheDocument(),
    )
  })
})
