/**
 * RTL tests for ForgotPasswordPage — "forgot password" step A.
 *
 * fetch is mocked globally; no real network calls are made. Covers:
 *  1. Renders the email form.
 *  2. Successful submit → generic "check your inbox" success message
 *     (indistinguishable whether or not the address matches an account —
 *     the backend already unifies this; the page just shows what it gets).
 *  3. Infra error (e.g. rate limit) → error alert with the server message.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ForgotPasswordPage from '../pages/ForgotPasswordPage'

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function mockFetchSequence(responses: Response[]) {
  let idx = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    return responses[idx++] ?? responses[responses.length - 1]
  })
}

function renderForgotPasswordPage() {
  return render(
    <MemoryRouter initialEntries={['/password/reset/request']}>
      <Routes>
        <Route path="/password/reset/request" element={<ForgotPasswordPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('ForgotPasswordPage', () => {
  it('renders the email form', async () => {
    mockFetchSequence([jsonResponse(BOOTSTRAP_RESPONSE)])
    renderForgotPasswordPage()

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /Link anfordern/i })).toBeInTheDocument()
  })

  it('shows the generic success message regardless of whether the account exists', async () => {
    mockFetchSequence([jsonResponse(BOOTSTRAP_RESPONSE), jsonResponse({ ok: true })])
    renderForgotPasswordPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'unknown@example.com')
    await user.click(screen.getByRole('button', { name: /Link anfordern/i }))

    await waitFor(() => expect(screen.getByText('E-Mail unterwegs')).toBeInTheDocument())
    expect(screen.getByText(/Falls zu dieser Adresse ein Konto existiert/i)).toBeInTheDocument()
  })

  it('shows an error message when the request is rate-limited', async () => {
    mockFetchSequence([
      jsonResponse(BOOTSTRAP_RESPONSE),
      jsonResponse({ error: { key: 'rate_limited', message: 'Zu viele Anfragen.' } }, 429),
    ])
    renderForgotPasswordPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'test@example.com')
    await user.click(screen.getByRole('button', { name: /Link anfordern/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    // The friendly, localized message replaces the raw server message for rate limits.
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Zu viele Versuche. Bitte warte einen Moment und versuche es erneut.',
    )
  })

  it('links back to the login page', async () => {
    mockFetchSequence([jsonResponse(BOOTSTRAP_RESPONSE)])
    renderForgotPasswordPage()

    await screen.findByLabelText(/E-Mail-Adresse/i)
    const user = userEvent.setup()
    await user.click(screen.getByRole('link', { name: /Zurück zur Anmeldung/i }))

    await waitFor(() => expect(screen.getByTestId('login-page')).toBeInTheDocument())
  })
})
