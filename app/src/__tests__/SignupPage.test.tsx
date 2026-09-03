/**
 * RTL tests for SignupPage — user-visible behaviour only.
 *
 * fetch is mocked globally so no network calls are made. This page reuses the
 * magic-link mechanism verbatim (bootstrap + POST /login with a fixed
 * `r=/signup/account` return-to), so the test shape mirrors LoginPage.test.tsx.
 */

import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import SignupPage from '../pages/SignupPage'

const BOOTSTRAP_RESPONSE = { csrf_token: 'test-csrf', user: null }

function mockFetchSuccess() {
  const responses = [
    new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
    new Response(JSON.stringify({ ok: true }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    }),
  ]
  let idx = 0
  vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
    return responses[idx++] ?? responses[responses.length - 1]
  })
}

function renderSignupPage() {
  return render(
    <MemoryRouter initialEntries={['/signup']}>
      <Routes>
        <Route path="/signup" element={<SignupPage />} />
        <Route path="/login" element={<div data-testid="login-page" />} />
      </Routes>
    </MemoryRouter>,
  )
}

beforeEach(() => {
  vi.restoreAllMocks()
})

describe('SignupPage', () => {
  it('renders email input and submit button', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderSignupPage()

    expect(screen.getByLabelText(/E-Mail-Adresse/i)).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /magic-link senden/i })).toBeInTheDocument()
  })

  it('shows "Link gesendet" confirmation after successful submit, using the r=/signup/account return-to', async () => {
    mockFetchSuccess()
    renderSignupPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'new-owner@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByText('Link gesendet')).toBeInTheDocument())
    expect(screen.getByText(/new-owner@example.com/)).toBeInTheDocument()

    // The POST body must carry the signup return-to path, not a board path.
    const loginCall = vi
      .mocked(globalThis.fetch)
      .mock.calls.find(
        ([input]) => String(input).includes('/login') && !String(input).includes('verify'),
      )
    expect(loginCall).toBeDefined()
    const body = JSON.parse(String((loginCall?.[1] as RequestInit)?.body))
    expect(body.r).toBe('/signup/account')
  })

  it('links to /login with the same return-to for existing accounts', () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    )
    renderSignupPage()

    const link = screen.getByRole('link', { name: /anmelden/i })
    expect(link).toHaveAttribute('href', `/login?r=${encodeURIComponent('/signup/account')}`)
  })

  it('shows error message when the request fails', async () => {
    const responses = [
      new Response(JSON.stringify(BOOTSTRAP_RESPONSE), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
      new Response(
        JSON.stringify({ error: { key: 'rate_limited', message: 'Zu viele Anfragen.' } }),
        { status: 429, headers: { 'Content-Type': 'application/json' } },
      ),
    ]
    let idx = 0
    vi.spyOn(globalThis, 'fetch').mockImplementation(async () => {
      return responses[idx++] ?? responses[responses.length - 1]
    })
    renderSignupPage()

    const user = userEvent.setup()
    await user.type(screen.getByLabelText(/E-Mail-Adresse/i), 'test@example.com')
    await user.click(screen.getByRole('button', { name: /magic-link senden/i }))

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument())
    expect(screen.getByRole('alert')).toHaveTextContent('Zu viele Anfragen.')
  })
})
