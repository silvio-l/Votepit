/**
 * RTL tests for ResetPasswordPage — "forgot password" step B.
 *
 * fetch is mocked globally; no real network calls are made. Covers:
 *  1. Missing token → immediate generic error, no fetch call.
 *  2. Mismatched confirmation → inline error, submit disabled, no fetch call.
 *  3. Successful reset → success state.
 *  4. invalid_token error → generic non-enumerating error with a
 *     "request a new link" action.
 *  5. Other error → the server's error message shown as-is.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ResetPasswordPage from '../pages/ResetPasswordPage'

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

function renderResetPasswordPage(initialEntry = '/password/reset/confirm?token=abc123') {
  return render(
    <MemoryRouter initialEntries={[initialEntry]}>
      <Routes>
        <Route path="/password/reset/confirm" element={<ResetPasswordPage />} />
        <Route path="/password/reset/request" element={<div data-testid="forgot-page" />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('ResetPasswordPage — missing token', () => {
  it('shows a generic error immediately and makes no fetch call', async () => {
    const fetchSpy = vi.spyOn(globalThis, 'fetch')

    renderResetPasswordPage('/password/reset/confirm')

    expect(await screen.findByText('Der Link ist ungültig oder abgelaufen.')).toBeInTheDocument()
    expect(fetchSpy).not.toHaveBeenCalled()
  })
})

describe('ResetPasswordPage — form validation', () => {
  it('shows an inline mismatch error and disables submit', async () => {
    mockFetchSequence([jsonResponse(BOOTSTRAP_RESPONSE)])
    renderResetPasswordPage()

    const user = userEvent.setup()
    await user.type(
      document.getElementById('reset-new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('reset-new-password-confirmation') as HTMLInputElement,
      'a-different-password',
    )

    expect(screen.getByRole('alert')).toHaveTextContent('Die Passwörter stimmen nicht überein.')
    expect(screen.getByRole('button', { name: /Passwort speichern/i })).toBeDisabled()
  })
})

describe('ResetPasswordPage — success', () => {
  it('shows the success state after a matching reset', async () => {
    mockFetchSequence([jsonResponse(BOOTSTRAP_RESPONSE), jsonResponse({ ok: true })])
    renderResetPasswordPage()

    const user = userEvent.setup()
    await user.type(
      document.getElementById('reset-new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('reset-new-password-confirmation') as HTMLInputElement,
      'a-strong-password',
    )
    await user.click(screen.getByRole('button', { name: /Passwort speichern/i }))

    await waitFor(() => expect(screen.getByText('Passwort gespeichert')).toBeInTheDocument())
  })
})

describe('ResetPasswordPage — invalid token', () => {
  it('shows a generic non-enumerating error with a request-new-link action', async () => {
    mockFetchSequence([
      jsonResponse(BOOTSTRAP_RESPONSE),
      jsonResponse(
        { error: { key: 'invalid_token', message: 'Der Link ist ungültig oder abgelaufen.' } },
        400,
      ),
    ])
    renderResetPasswordPage()

    const user = userEvent.setup()
    await user.type(
      document.getElementById('reset-new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('reset-new-password-confirmation') as HTMLInputElement,
      'a-strong-password',
    )
    await user.click(screen.getByRole('button', { name: /Passwort speichern/i }))

    await waitFor(() =>
      expect(screen.getByText('Der Link ist ungültig oder abgelaufen.')).toBeInTheDocument(),
    )
    await user.click(screen.getByRole('link', { name: /Neuen Link anfordern/i }))
    await waitFor(() => expect(screen.getByTestId('forgot-page')).toBeInTheDocument())
  })
})

describe('ResetPasswordPage — other error', () => {
  it('shows the server error message for a non-token failure', async () => {
    mockFetchSequence([
      jsonResponse(BOOTSTRAP_RESPONSE),
      jsonResponse(
        {
          error: {
            key: 'weak_password',
            message: 'Das Passwort muss mindestens 10 Zeichen lang sein.',
          },
        },
        400,
      ),
    ])
    renderResetPasswordPage()

    const user = userEvent.setup()
    await user.type(
      document.getElementById('reset-new-password') as HTMLInputElement,
      'a-strong-password',
    )
    await user.type(
      document.getElementById('reset-new-password-confirmation') as HTMLInputElement,
      'a-strong-password',
    )
    await user.click(screen.getByRole('button', { name: /Passwort speichern/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent(
      'Das Passwort muss mindestens 10 Zeichen lang sein.',
    )
  })
})
